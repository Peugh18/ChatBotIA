<?php

namespace App\Services;

use App\Events\ClientStatusUpdated;
use App\Events\MessageReceived;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\DeliveryZone;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class MessageHandler
{
    protected WhatsAppService    $whatsAppService;
    protected GeminiService      $geminiService;
    protected ImageService       $imageService;
    protected ProductSearchService $searchService;
    protected IntentDetector       $intent;

    public function __construct(
        WhatsAppService    $whatsAppService,
        GeminiService      $geminiService,
        ImageService       $imageService,
        ProductSearchService $searchService,
        IntentDetector       $intent
    ) {
        $this->whatsAppService = $whatsAppService;
        $this->geminiService   = $geminiService;
        $this->imageService    = $imageService;
        $this->searchService   = $searchService;
        $this->intent          = $intent;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENTRY POINT
    // ─────────────────────────────────────────────────────────────────────────

    public function process($messageData, $contactData): void
    {
        $phone     = $messageData['from'];
        $name      = $contactData['profile']['name'] ?? null;
        $messageId = $messageData['id'];

        // Atomic lock prevents duplicate concurrent processing
        $lock = cache()->lock("wamsg_{$messageId}", 15);
        if (!$lock->get()) {
            Log::info("Concurrent duplicate blocked: {$messageId}");
            return;
        }

        try {
            if (Message::where('meta_message_id', $messageId)->exists()) {
                Log::info("Already processed (dedup): {$messageId}");
                return;
            }

            $client = Client::firstOrCreate(
                ['phone' => $phone],
                ['name' => $name, 'status' => 'NUEVO', 'priority' => 'BAJA']
            );

            if ($name && empty($client->name)) {
                $client->update(['name' => $name]);
            }

            $type    = $messageData['type'];
            $body    = ($type === 'text')  ? ($messageData['text']['body'] ?? null) : null;
            $mediaId = ($type === 'image') ? ($messageData['image']['id'] ?? null)  : null;

            $msg = Message::create([
                'client_id'       => $client->id,
                'from_me'         => false,
                'body'            => $body,
                'type'            => $type,
                'meta_message_id' => $messageId,
            ]);

            broadcast(new MessageReceived($msg))->toOthers();
            broadcast(new ClientStatusUpdated($client->fresh()))->toOthers();

            $client->update([
                'last_interaction_at' => now(),
                'last_customer_message_at' => now(),
            ]);

            if ($type === 'image') {
                $this->handleImageMessage($client, $mediaId);
            } elseif ($type === 'text') {
                $this->handleTextMessage($client, $body);
            } elseif ($type === 'interactive') {
                $interactive = $messageData['interactive'] ?? [];
                $reply       = $interactive['button_reply'] ?? $interactive['list_reply'] ?? [];
                $option      = $reply['title'] ?? '';
                $btnId       = $reply['id'] ?? '';

                // If the client tapped "Lo quiero" on a product card, store the product_id in state
                if (str_starts_with($btnId, 'buy_')) {
                    $pid = (int) str_replace('buy_', '', $btnId);
                    $state = $client->state ?? ['step' => 'start'];
                    $state['product_id'] = $pid;
                    $client->update(['state' => $state]);
                }

                // If the client selected a category from a List Message
                if (str_starts_with($btnId, 'cat_')) {
                    $catId = (int) str_replace('cat_', '', $btnId);
                    $state = $client->state ?? ['step' => 'start'];
                    $state['category_id'] = $catId;
                    $client->update(['state' => $state]);
                }

                $this->handleTextMessage($client, $option);
            }

            $this->whatsAppService->markAsRead($messageId);
        } finally {
            $lock->release();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEXT MESSAGE ROUTER
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleTextMessage(Client $client, ?string $text): void
    {
        if (empty($text)) return;

        $state     = $client->state ?? ['step' => 'start'];
        $cleanText = strtolower(trim($text));

        // If client replies after we asked for confirmation, stop follow-ups immediately
        if ($client->confirmation_requested_at && !$client->followup_stopped_at) {
            $client->update(['followup_stopped_at' => now()]);
        }

        // Universal cancel
        if (in_array($cleanText, ['cancelar', 'salir', 'cancel', 'stop'])) {
            $this->reply($client, "Entendido, cancelé el proceso. 😊 ¿Hay algo más en que pueda ayudarte?");
            $this->updateClientAndBroadcast($client, [
                'state' => ['step' => 'start'],
                'status' => 'INTERESADO',
                'opted_out_at' => now(),
                'confirmation_requested_at' => null,
                'followup_3_sent_at' => null,
                'followup_15_sent_at' => null,
                'followup_stopped_at' => null,
            ]);
            return;
        }

        // ── Payment method selection (Yape vs Tarjeta) ───────────────────────
        // If the client is awaiting payment and picks a method, send method-specific info.
        if ($client->status === 'ESPERANDO PAGO') {
            $payment = $this->detectPaymentMethod($cleanText);
            if ($payment === 'yape') {
                $state['payment_method'] = 'Yape';
                $state['step'] = 'awaiting_payment';
                $client->update(['state' => $state]);
                $this->reply($client, $this->buildYapeOnlyMessage($this->getExpectedPaymentAmount($state)));
                return;
            }
            if ($payment === 'tarjeta') {
                $state['payment_method'] = 'Tarjeta';
                $state['step'] = 'awaiting_payment';
                $client->update(['state' => $state]);
                $this->reply($client, $this->buildCardOnlyMessage($this->getExpectedPaymentAmount($state)));
                return;
            }
        }

        // Shortcut: client tapped "Lo quiero 💚" on a product card — skip AI, go straight to variant selection.
        // Only fires from idle/confirmation steps so it doesn't interrupt collect_address/name/shipping/variant.
        $currentStep = $state['step'] ?? 'start';
        $productIdFromState = $state['product_id'] ?? null;
        $allowBuyShortcut = in_array($currentStep, ['start', 'waiting_confirmation'], true);
        if ($allowBuyShortcut && $productIdFromState && $this->looksLikeBuyIntent($cleanText)) {
            $product = Product::with('variants')->find($productIdFromState);
            if ($product) {
                $this->sendProductCard($client, $product);
                $opts = $this->formatVariantsForCustomer($product);
                $this->reply($client, "¿En qué color y talla lo quieres, hermosa?\n\nLo tengo disponible en {$opts}.\n\nPuedes escribirme por ejemplo: *Negro M* ✨");
                $state['step'] = 'collect_variant';
                $client->update(['state' => $state]);
                return;
            }
        }

        // Simple data-collection steps (no AI needed — just store the value)
        $dataStep = $state['step'] ?? 'start';
        if ($dataStep === 'collect_delivery_district') {
            $zone = $this->findDeliveryZoneFromText($text);
            if (!$zone) {
                $this->reply($client, "Hermosa, ¿me confirmas el distrito exacto de Lima para cotizarte el delivery por motorizado? 🛵");
                return;
            }
            $state['district'] = $zone->district;
            $state['delivery_cost'] = (float) $zone->motorizado_cost;
            $state['step'] = 'collect_payment_method';
            $client->update(['state' => $state]);
            $this->reply($client,
                "El delivery para *{$zone->district}* es de *S/ " . number_format($zone->motorizado_cost, 2) . "*.\n\n"
                . "El pago del motorizado se cancela al recibir el vestido, ¡y las entregas son de lunes a sábado de 5 a 9 pm! 🛵\n\n"
                . "El vestidito queda en *S/ " . number_format($this->getOrderProductTotal($state), 2) . "*. ¿Prefieres pagar por *Yape* o *tarjeta/link*, hermosa?"
            );
            $this->sendButtons($client, '¿Cómo prefieres pagar?', ['Yape 📱', 'Tarjeta 💳']);
            return;
        }
        if ($dataStep === 'collect_payment_method') {
            $payment = $this->detectPaymentMethod($cleanText);
            if (!$payment) {
                $this->reply($client, "Claro hermosa, para dejarlo separado dime si prefieres pagar por *Yape* o por *tarjeta/link* ✨");
                $this->sendButtons($client, '¿Cómo prefieres pagar?', ['Yape 📱', 'Tarjeta 💳']);
                return;
            }
            $state['payment_method'] = $payment === 'yape' ? 'Yape' : 'Tarjeta';
            $state['step'] = 'awaiting_payment';
            $client->update(['state' => $state]);
            $this->updateClientAndBroadcast($client, ['status' => 'ESPERANDO PAGO', 'priority' => 'ALTA']);
            $this->reply($client, $payment === 'yape'
                ? $this->buildYapeOnlyMessage($this->getExpectedPaymentAmount($state))
                : $this->buildCardOnlyMessage($this->getExpectedPaymentAmount($state))
            );
            return;
        }
        if ($dataStep === 'collect_delivery_details_motorizado') {
            $state['delivery_details'] = $text;
            $this->finalizePaidOrder($client, $state);
            return;
        }
        if ($dataStep === 'collect_delivery_details_shalom') {
            $state['delivery_details'] = $text;
            $this->finalizePaidOrder($client, $state);
            return;
        }
        if ($dataStep === 'collect_shipping') {
            $shippingChoice = $this->normalizeShippingChoice($text);
            if (!$shippingChoice) {
                $this->reply($client,
                    "Hermosa, para avanzar necesito que elijas una opción de envío válida 😊\n\n"
                    . "Puede ser *Motorizado* o *Shalom*."
                );
                $this->sendButtons($client, 'Elige tu método de envío:', ['Motorizado 🛵', 'Shalom 🚚']);
                return;
            }
            $state['shipping'] = $shippingChoice;
            if ($shippingChoice === 'Motorizado') {
                $state['step'] = 'collect_delivery_district';
                $client->update(['state' => $state]);
                $this->reply($client, "Perfecto, hermosa 🛵 ¿A qué distrito sería el envío?");
                return;
            }
            $state['shipping_cost'] = IntentDetector::getShalomLima();
            $state['step'] = 'collect_payment_method';
            $client->update(['state' => $state]);
            $this->reply($client,
                "Por *Shalom* el costo de envío es de *S/ 10 para Lima* y *S/ 12 para provincia* en promedio, y llega de 1 a 3 días hábiles. 🚚\n\n"
                . "El total a pagar ahora sería *S/ " . number_format($this->getExpectedPaymentAmount($state), 2) . "*. ¿Prefieres *Yape* o *tarjeta/link*, hermosa?"
            );
            $this->sendButtons($client, '¿Cómo prefieres pagar?', ['Yape 📱', 'Tarjeta 💳']);
            return;
        }

        // Variant selection (uses AI for fuzzy matching)
        if ($dataStep === 'collect_variant') {
            $this->handleVariantSelection($client, $text, $state);
            return;
        }

        // Pre-AI intent detection: saves Gemini quota for trivial messages.
        // Only short greetings, FAQs and quick intents are answered locally;
        // anything else falls through to the contextual AI.
        if ($dataStep === 'start' && ($intent = $this->intent->detect($text)) !== null) {
            if ($this->handleQuickIntent($client, $intent)) {
                return;
            }
        }

        // If the client asks for a photo of the current product, send it immediately
        $productIdFromState = $state['product_id'] ?? null;
        if ($productIdFromState && $this->looksLikePhotoRequest($text)) {
            $this->sendProductPhoto($client, $productIdFromState);
        }

        // Everything else → full contextual AI
        $this->processWithContextualAI($client, $text, $state);
    }

    /**
     * Replies for the lightweight intents detected before invoking the AI.
     * Returns true if the intent was handled here (skip AI), false otherwise.
     */
    protected function handleQuickIntent(Client $client, array $intent): bool
    {
        switch ($intent['type']) {
            case 'greeting':
                $name = $client->name ? ", {$client->name}" : '';
                $this->reply($client,
                    "¡Hola{$name}! 👋 Soy *Roma*, asesora de *Roma Store* — tu tienda de ropa peruana.\n\n"
                    . "¿Qué estás buscando hoy? Puedo mostrarte:\n\n"
                    . "👕 Polos y poleras\n"
                    . "🧥 Casacas y chompas\n"
                    . "👖 Pantalones y jeans\n\n"
                    . "O escríbeme directamente lo que te interesa (color, talla, presupuesto)."
                );
                if (in_array($client->status, ['NUEVO', null], true)) {
                    $this->updateClientAndBroadcast($client, ['status' => 'INTERESADO']);
                }
                return true;

            case 'escalate':
                $this->reply($client,
                    "¡Claro! 🙌 En unos minutos un asesor del equipo te escribe por aquí mismo. "
                    . "Mientras tanto, si quieres ir adelantando, dime qué prenda te interesa."
                );
                $this->updateClientAndBroadcast($client, ['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
                Log::info("Client {$client->phone} requested human (intent detector).");
                return true;

            case 'business_hours':
                $this->reply($client,
                    "🕒 Nuestro horario de atención por WhatsApp:\n\n*" . IntentDetector::getBusinessHours() . "*\n\n"
                    . "Pero puedes escribirnos cuando quieras y te respondo en cuanto abramos. 😊"
                );
                return true;

            case 'store_location':
                $this->reply($client,
                    "📍 Somos una tienda *online* (vendemos por *TikTok Live* y *WhatsApp*) con envíos a todo el Perú:\n\n"
                    . "🏍️ *Lima*: delivery motorizado en el día.\n"
                    . "📦 *Provincias*: por Shalom (1–3 días).\n\n"
                    . "¿Quieres ver el catálogo? Dime qué buscas."
                );
                return true;

            case 'payment_info':
                $this->reply($client, $this->buildPaymentInfoMessage());
                return true;

            case 'delivery_quote':
                $zone = DeliveryZone::find($intent['zone_id'] ?? null);
                if ($zone) {
                    $this->reply($client,
                        "🏍️ *Delivery a {$zone->district}*:\n\n"
                        . "• *Motorizado*: S/ " . number_format($zone->motorizado_cost, 2) . " (" . IntentDetector::getMotorizadoWindow() . ")\n"
                        . "• *Shalom Lima*: S/ " . number_format($zone->shalom_cost, 2) . "\n\n"
                        . "¿Qué método prefieres, hermosa? 💛"
                    );
                    return true;
                }
                return false;

            case 'delivery_info':
                $this->reply($client,
                    "📦 *Opciones de envío:*\n\n"
                    . "🏍️ *Motorizado* (Lima): tarifa según distrito — " . IntentDetector::getMotorizadoWindow() . ".\n"
                    . "📦 *Shalom Lima*: S/ " . IntentDetector::getShalomLima() . ".\n"
                    . "📦 *Shalom Provincia*: ~S/ " . IntentDetector::getShalomProvincia() . " en promedio.\n\n"
                    . "¿A qué distrito te lo enviamos? Así te paso el costo exacto."
                );
                return true;

            case 'catalog_request':
                $this->sendCategoryList($client);
                $top = $this->searchService->getTopSelling(5);
                if ($top->isNotEmpty()) {
                    $list = $top->map(fn($p) => '• *' . $p->name . '* — S/ ' . number_format($p->price, 2))->join("\n");
                    $this->reply($client,
                        "🔥 *Lo más vendido esta semana:*\n\n{$list}\n\n"
                        . "Dime cuál te llama la atención (o mándame foto de algo similar) y te paso colores, tallas y stock."
                    );
                }
                $this->updateClientAndBroadcast($client, ['status' => 'INTERESADO']);
                return true;

            case 'thanks':
                $this->reply($client, "¡Gracias a ti! 💛 Cualquier cosa estoy por aquí.");
                return true;
        }

        return false;
    }

    /**
     * Stamp the first time we (bot or human) ever replied to this client.
     * Called from reply(). Used for first-response SLA metrics.
     */
    protected function touchFirstResponseAt(Client $client): void
    {
        if (!Schema::hasColumn('clients', 'first_response_at')) return;
        if (!empty($client->first_response_at)) return;
        $client->forceFill(['first_response_at' => now()])->save();
    }

    /**
     * Single source of truth for the payment-info message.
     * Used both by the quick intent detector and by completeOrder().
     */
    protected function buildPaymentInfoMessage(?float $amount = null): string
    {
        $yape  = IntentDetector::getYapeNumber();
        $owner = IntentDetector::getYapeHolder();
        $amountText = $amount ? "Monto: *S/ " . number_format($amount, 2) . "*\n\n" : '';
        return "💸 *Métodos de pago*\n\n"
            . $amountText
            . "🔹 *Yape* a este mismo número: *{$yape}* ({$owner}).\n"
            . "   Envíame la captura y confirmamos en el acto. ⚡\n\n"
            . "🔹 *Tarjeta / Link de pago*: si prefieres pagar con tarjeta, "
            . "envíame estos datos y te genero el link:\n"
            . "   • Nombre completo\n"
            . "   • Correo electrónico\n"
            . "   • Número de celular\n"
            . "   • Monto a pagar";
    }

    /**
     * Yape-only payment instructions (after client picks Yape button).
     */
    protected function buildYapeOnlyMessage(?float $amount = null): string
    {
        $yape  = IntentDetector::getYapeNumber();
        $owner = IntentDetector::getYapeHolder();
        $amountText = $amount ? "El monto a yapear es *S/ " . number_format($amount, 2) . "*.\n\n" : '';
        return "Perfecto, hermosa 💕\n\n"
            . $amountText
            . "Puedes yapear al número:\n"
            . "*{$yape}* — {$owner}\n\n"
            . "Cuando termines, envíame aquí mismo la captura de pantalla para validarlo y dejar tu pedido programado. 📸";
    }

    /**
     * Card-only payment instructions (after client picks Tarjeta button).
     */
    protected function buildCardOnlyMessage(?float $amount = null): string
    {
        $amountText = $amount ? "El monto del link sería *S/ " . number_format($amount, 2) . "*.\n\n" : '';
        return "Claro, reina 💳\n\n"
            . $amountText
            . "Para generarte el link de pago necesito estos datos en un solo mensaje:\n\n"
            . "• Nombre completo\n"
            . "• Correo electrónico\n"
            . "• Número de celular\n"
            . "• Monto a pagar\n\n"
            . "En cuanto los reciba te envío el link seguro para que pagues con tu tarjeta. 🔒";
    }

    /**
     * Detect which payment method (Yape / Tarjeta) the client picked.
     * Returns 'yape', 'tarjeta', or null.
     */
    protected function detectPaymentMethod(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if (preg_match('/\b(yape|yapeo|yapear|plin)\b/u', $t)) return 'yape';
        if (preg_match('/\b(tarjeta|card|visa|mastercard|cr[eé]dito|d[eé]bito|link)\b/u', $t)) return 'tarjeta';
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CORE: CONTEXTUAL AI ENGINE
    // ─────────────────────────────────────────────────────────────────────────

    protected function processWithContextualAI(Client $client, string $text, array $state): void
    {
        $history   = $this->buildConversationHistory($client);
        $history[] = ['role' => 'user', 'parts' => [['text' => $text]]];

        $systemPrompt = $this->buildSystemPrompt($client, $state, $text);
        $raw          = $this->geminiService->chatWithHistory($history, $systemPrompt);

        if (!$raw) {
            $err = $this->geminiService->lastErrorCode;

            // Try local intent handling when Gemini is offline (quota/auth error).
            // This keeps the bot responsive for greetings, catalog, FAQs, etc.
            if (in_array($err, ['quota', 'auth', 'error'], true)) {
                $localIntent = $this->intent->detect($text);
                if ($localIntent && $this->handleQuickIntent($client, $localIntent)) {
                    return;
                }

                // If no local intent matched, give an honest, non-escalating message.
                if ($err === 'quota') {
                    $this->reply($client,
                        "Estoy con muchísima demanda en este momento 🤖\n\n"
                        . "Dame unos minutos y vuelve a escribirme. Mientras tanto, "
                        . "puedes decirme directamente qué prenda buscas (por ejemplo: *polo negro M*).\n\n"
                        . "Si es urgente, escribe *asesor* y te atiende una persona del equipo."
                    );
                } else {
                    $this->reply($client,
                        "Tuve un problema técnico momentáneo 😅 ¿Puedes repetirme lo que necesitas? "
                        . "Si sigue sin funcionar, escribe *asesor* y te ayuda una persona."
                    );
                }
                return;
            }

            // Unknown / unexpected failure
            $this->reply($client, "Tuve un problema técnico momentáneo 😅 ¿Puedes repetirme lo que necesitas?");
            return;
        }

        $parsed = json_decode($this->cleanJsonResponse($raw), true);

        // If JSON parse fails, send raw text as fallback
        if (!$parsed || !isset($parsed['message'])) {
            $this->reply($client, $raw);
            return;
        }

        $this->reply($client, $parsed['message']);

        // If the AI promised a photo in its response, actually send it
        $productIdFromState = $state['product_id'] ?? null;
        if ($productIdFromState && $this->looksLikePhotoPromise($parsed['message'])) {
            $this->sendProductPhoto($client, $productIdFromState);
        }

        // ── Auto-start confirmation follow-up timers ─────────────────────────
        // If the AI just asked the client to confirm an order, arm the 3/15 min timers.
        if ($this->looksLikeConfirmationRequest($parsed['message']) && !in_array($client->status, ['ESPERANDO PAGO', 'PAGO RECIBIDO', 'EN PREPARACIÓN', 'FINALIZADO', 'ABANDONADO'], true)) {
            $client->update([
                'confirmation_requested_at' => now(),
                'followup_3_sent_at'       => null,
                'followup_15_sent_at'      => null,
                'followup_stopped_at'      => null,
            ]);
            // Send interactive buttons so the client can confirm with 1 tap
            $this->sendButtons($client, 'Confirma tu pedido:', ['Sí, lo quiero 💚', 'Ver otros']);
        }

        $this->executeAIAction($client, $parsed, $state);

        // Clear one-time category filter so next messages aren't stuck in that category
        if (!empty($state['category_id'])) {
            $freshState = $client->fresh()->state ?? [];
            unset($freshState['category_id']);
            $client->update(['state' => $freshState]);
        }
    }

    /**
     * Detect if the AI message is asking the client to confirm a purchase.
     */
    protected function looksLikeConfirmationRequest(string $message): bool
    {
        $needle = mb_strtolower($message);
        $keywords = ['confirmas', 'realizar el pedido', 'lo quieres', 'lo llevas', 'te lo envío', 'lo aparto', 'confirmame'];
        foreach ($keywords as $k) {
            if (str_contains($needle, $k)) return true;
        }
        return false;
    }

    /**
     * Detect if the client is expressing intent to buy after seeing a product card.
     */
    protected function looksLikeBuyIntent(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        // Multi-word phrases (safe with substring match)
        $phrases = [
            'lo quiero', 'lo llevo', 'me lo llevo', 'lo compro', 'me lo quedo',
            'lo quiero comprar', 'sí lo quiero', 'si lo quiero',
            'sí lo llevo', 'si lo llevo', 'lo voy a llevar', 'me lo das',
        ];
        foreach ($phrases as $p) {
            if (str_contains($t, $p)) return true;
        }

        // Short ambiguous words: only match as standalone tokens (word boundary).
        // Avoids "siempre" → "si", "casi" → "si", "decisión" → "si", etc.
        if (preg_match('/(^|[^\p{L}])(sí|si|claro|dale|ok|okey|listo|compro|quiero)([^\p{L}]|$)/u', $t)) {
            return true;
        }

        return false;
    }

    /**
     * Normalize a shipping-method response. Returns 'Motorizado', 'Shalom', or null if invalid.
     */
    protected function normalizeShippingChoice(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        // Motorizado: any of these keywords
        if (preg_match('/\b(motorizado|motoriz|moto|delivery|repartidor|lima)\b/u', $t)) {
            return 'Motorizado';
        }
        // Shalom: agency / province
        if (preg_match('/\b(shalom|provinci|agencia|courier)\b/u', $t)) {
            return 'Shalom';
        }
        return null;
    }

    /**
     * Detect if the client is asking for a photo/image of the product.
     */
    protected function looksLikePhotoRequest(string $text): bool
    {
        $keywords = ['foto', 'imagen', 'ver foto', 'muestrame foto', 'muéstrame foto', 'fotos', 'imágenes', 'ver imagen', 'mandame foto', 'mándame foto', 'pasame foto', 'pásame foto', 'envia foto', 'envía foto'];
        foreach ($keywords as $k) {
            if (str_contains(mb_strtolower($text), $k)) return true;
        }
        return false;
    }

    /**
     * Detect if the AI response text promises to send a photo.
     */
    protected function looksLikePhotoPromise(string $message): bool
    {
        $needle = mb_strtolower($message);
        $keywords = [
            'te paso la foto', 'te mando la foto', 'te envío la foto', 'aquí tienes la foto',
            'aquí está la foto', 'te comparto la foto', 'te dejo la foto', 'foto del',
            'imagen del', 'foto del producto', 'imagen del producto', 'aquí la foto',
            'te envio la foto', 'mira la foto', 've la foto',
        ];
        foreach ($keywords as $k) {
            if (str_contains($needle, $k)) return true;
        }
        return false;
    }

    /**
     * Send the actual product photo via WhatsApp media message.
     */
    protected function sendProductPhoto(Client $client, int $productId): void
    {
        $product = Product::find($productId);
        if (!$product || empty($product->image_url)) {
            return; // Silently skip if no image available
        }

        $this->whatsAppService->sendMediaMessage(
            $client->phone,
            'image',
            $product->image_url,
            $product->name
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SYSTEM PROMPT BUILDER
    // ─────────────────────────────────────────────────────────────────────────

    protected function buildSystemPrompt(Client $client, array $state, ?string $userText = null): string
    {
        $client->loadMissing(['orders', 'tags', 'notes']);

        $ordersHistory = $client->orders->isEmpty()
            ? 'Sin compras previas'
            : $client->orders->map(fn($o) => "  Pedido #{$o->id}: S/{$o->total} — {$o->status}")->join("\n");

        $budget      = $client->budget_estimate ? "S/ {$client->budget_estimate}" : 'No determinado';
        $prefs       = !empty($client->preferences) ? json_encode($client->preferences, JSON_UNESCAPED_UNICODE) : 'No registradas';
        $currentStep = $state['step'] ?? 'start';

        // ── CRM context the AI can read & mutate (auto-tags + auto-notes) ─────────────────────────────────────
        $availableTags = Tag::orderBy('name')->pluck('name')->all();
        $availableTagsStr = empty($availableTags) ? '(ninguna)' : implode(', ', $availableTags);

        $currentTags = $client->tags->pluck('name')->all();
        $currentTagsStr = empty($currentTags) ? '(ninguna)' : implode(', ', $currentTags);

        $recentNotes = $client->notes->take(5);
        $recentNotesStr = $recentNotes->isEmpty()
            ? 'Sin notas previas.'
            : $recentNotes->map(fn($n) => '  • ' . str_replace(["\n", "\r"], ' ', mb_strimwidth($n->body, 0, 140, '…')))->join("\n");

        // ── Tarifas de delivery (referencia para la IA) ──────────────────────
        $deliveryRows = DeliveryZone::where('active', true)
            ->orderBy('district')
            ->get(['district', 'motorizado_cost'])
            ->map(fn($z) => "  • {$z->district}: S/ " . number_format($z->motorizado_cost, 2))
            ->join("\n");

        $yape  = IntentDetector::getYapeNumber();
        $owner = IntentDetector::getYapeHolder();

        // ── Sub-catálogo dinámico ────────────────────────────────────────────
        // Si el mensaje del usuario contiene filtros (color, talla, categoría,
        // rango de precio), enviamos SOLO los productos relevantes (top 6) en
        // vez de todo el catálogo. Ahorra tokens y mejora la calidad.
        $filters = $userText ? $this->intent->extractProductFilters($userText) : [];

        // If client selected a category from a List Message, filter by that category
        $categoryIdFromState = $state['category_id'] ?? null;
        if ($categoryIdFromState) {
            $catProducts = Product::with('variants')->where('category_id', $categoryIdFromState)->limit(6)->get();
            if ($catProducts->isEmpty()) {
                $catalog = "(La categoría seleccionada no tiene productos disponibles)\n\n" . $this->searchService->getCatalogForContext();
            } else {
                $catalog = "(Productos de la categoría seleccionada)\n\n" . $this->searchService->formatForPrompt($catProducts);
            }
        } elseif (!empty($filters)) {
            $matches = $this->searchService->search($filters);
            $catalog = $matches->isNotEmpty()
                ? "(Resultados filtrados para tu búsqueda)\n\n" . $this->searchService->formatForPrompt($matches)
                : $this->searchService->getCatalogForContext();
        } else {
            $catalog = $this->searchService->getCatalogForContext();
        }

        $isReturning = !$client->orders->isEmpty();
        $returningHint = $isReturning
            ? "Es CLIENTE RECURRENTE — agradécele de forma personal y considera ofrecerle un beneficio (envío gratis, descuento sutil, primicia)."
            : "Es CLIENTE NUEVO — genera confianza con calidez, social proof real del catálogo (más vendidos) y urgencia honesta.";

        return <<<PROMPT
Eres *Roma*, asesora de ventas senior de *Roma Store* — tienda de ropa peruana de alta rotación que vende por TikTok Live y WhatsApp.
Tu misión es CERRAR VENTAS de forma natural, cálida y consultiva. Nunca digas que eres IA, bot o sistema.

═══ ESTILO DE COMUNICACIÓN ═══
• Español peruano, tono cercano, humano y de asesora de modas ("hermosa", "reina", "linda") sin exagerar.
• Mensajes CORTOS (máx 4–6 líneas). Una idea por mensaje.
• Usa emojis moderados (1–2 por mensaje, nunca más).
• Tutea siempre. Llama al cliente por su nombre si lo sabes.
• NUNCA inventes productos, precios, colores o tallas que no estén en el catálogo.
• Si no tienes algo, NO mientas: ofrece la alternativa más cercana del catálogo real.
• Prohibido responder con formato técnico de inventario como "LILA / T:S (1 disponibles)".
• Traduce disponibilidad a lenguaje natural: "lo tengo en lila talla S y M".

═══ PERFIL DEL CLIENTE ═══
Nombre: {$client->name}
Teléfono: {$client->phone}
Estado actual: {$client->status}
Prioridad: {$client->priority}
Presupuesto estimado: {$budget}
Preferencias detectadas: {$prefs}
Historial de compras:
{$ordersHistory}

{$returningHint}

═══ PASO ACTUAL DEL FLUJO ═══
{$currentStep}

═══ CATÁLOGO REAL CON STOCK (única fuente de verdad) ═══
{$catalog}

═══ CRM AUTOMÁTICO (tú lo administras, el asesor solo supervisa) ═══
Etiquetas disponibles: {$availableTagsStr}
Etiquetas actuales del cliente: {$currentTagsStr}
Notas internas recientes:
{$recentNotesStr}

Reglas para auto-etiquetar (usa tags_to_add / tags_to_remove con los nombres EXACTOS de la lista):
• "VIP" → ha comprado 3 o más veces o ticket > S/ 300.
• "Mayorista" → consulta por cantidad ≥ 5 unidades o pregunta por precios al por mayor.
• "Lima" → menciona distrito de Lima o pide envío motorizado.
• "Provincia" → menciona ciudad fuera de Lima o pide Shalom / agencia.
• "Recurrente" → tiene 1 o más pedidos previos.
• "Difícil" → muestra objeciones repetidas, regateo agresivo, tono hostil o se contradice.
• "TikTok Live" → menciona el live, TikTok, o vio el producto en transmisión.
No agregues etiquetas que no existan en la lista. No repitas etiquetas que ya estén.

Reglas para nota interna (campo internal_note, opcional, máx 200 chars):
• Solo escribe nota cuando descubras información valiosa NO obvia: preferencias, motivo de compra, contexto familiar, restricciones (talla difícil, alergias), modo de pago habitual, horario en que responde, color de piel/estilo personal mencionado, etc.
• NO escribas notas redundantes con la conversación visible. NO escribas notas en cada mensaje. Solo cuando sume.
• Estilo telegráfico: "Compra para su hija de 8 años", "Prefiere pagar contra entrega", "Disponible solo de noche".

═══ FLUJO OFICIAL DE VENTA (Roma Store) ═══
1. *Inicio*: el cliente envía foto del vestido / pregunta por nombre / pide catálogo.
   Si pide catálogo SIN especificar prenda → pídele nombre del vestido o que envíe foto.
2. *Identificación*: si manda foto, reconócela, busca en catálogo y verifica stock por color.
   Si manda foto PERO en texto pide otro color → confirma color solicitado y verifica ese stock.
3. *Presentación*: envía datos exactos (vestido + color + precio). No inventes.
4. *Cierre*: termina con esta frase EXACTA o muy cercana:
   "¿Nos confirmas si deseas realizar el pedido para poder ayudarte hermosa?"
5. *Si confirma* → primero pide color/talla si falta. Cuando ya esté elegida la variante, el sistema pedirá método de envío.
6. *Coordinación de envío ANTES del pago*:
   - Pregunta: "¿Prefieres envío por motorizado o por Shalom?"
   - Si elige motorizado, pide distrito y usa la tabla de tarifas reales abajo (NO inventes).
   - Si elige Shalom, informa: Lima S/10, provincia S/12 en promedio, llega de 1 a 3 días hábiles.
7. *Pago*: después de definir envío, ofrece Yape o tarjeta/link. Yape: {$yape} a nombre de {$owner}. Pide captura.
8. *Después de validar captura correcta*, solicita datos de entrega según método.
9. *Si es motorizado* solicita ESTOS datos exactos:
   ✅ NOMBRE DEL VESTIDO Y COLOR
   ✅ NOMBRE COMPLETO
   ✅ CELULAR
   ✅ DIRECCIÓN ESCRITA
   ✅ UBICACIÓN EN TIEMPO REAL (pin de WhatsApp)
   Aclárale: "Las entregas son de L–S, 5–9 p.m. El motorizado se paga aparte al recibir."
10. *Si es Shalom* solicita ESTOS datos exactos:
   ✅ Nombre del vestido y color
   ✅ Nombre completo
   ✅ Número de DNI
   ✅ Número de celular
   ✅ Sede exacta de Shalom

═══ INFO DE PAGO (úsalo tal cual) ═══
• Yape: *{$yape}* a nombre de *{$owner}* (este mismo WhatsApp).
• Tarjeta / Link de pago: solicita Nombre completo, Correo, Celular, Monto.

═══ TARIFAS DE DELIVERY MOTORIZADO POR DISTRITO (única fuente de verdad) ═══
{$deliveryRows}
Shalom Lima: S/ 10. Shalom Provincia: ~S/ 12.
Cuando el cliente mencione un distrito, RESPONDE con el costo EXACTO de la tabla.

═══ TÉCNICAS DE VENTA QUE DEBES APLICAR ═══
1. *Calificación rápida*: detecta presupuesto, ocasión de uso y talla en las primeras 2 respuestas, sin interrogar.
2. *Social proof real*: cuando un producto tiene mucho "sales_count" o stock bajo, menciónalo ("Este lo están pidiendo mucho esta semana", "Me quedan pocas tallas M").
3. *Anti-objeción*:
   • "está caro" → comunica valor (material, durabilidad, combinaciones) y ofrece la opción de menor precio del catálogo.
   • "lo pienso" → propone reservar el stock por unas horas y crea urgencia honesta basada en el stock real.
   • "después te aviso" → cierra con una pregunta simple ("¿prefieres negro o blanco?").
4. *Upsell / cross-sell*: cuando confirme un producto, sugiere UN solo complemento natural (no spam).
5. *Cierre asumido*: "¿te lo envío al mismo distrito de la última vez?" si es recurrente.
6. *Scoring*: estima la temperatura del lead 0–100 y devuélvela en lead_score.

═══ MENSAJES INTERACTIVOS AVANZADOS ═══
El sistema usa las features nativas de WhatsApp para una experiencia profesional:

• *List Messages* — menú desplegable nativo. Se usa cuando el cliente pide "catálogo" o "ver productos": aparece un menú con todas las categorías (Polos, Casacas, etc.) para que elija con 1 toque.
• *Product Cards* — tarjeta visual con imagen, precio, descripción y botones "Lo quiero 💚" / "Ver colores". Se envía automáticamente cuando identificas un producto específico que le interesa.
• *Fotos del producto* — si el cliente pide ver una foto o imagen del producto, di naturalmente "te paso la foto" o similar. El sistema enviará automáticamente la imagen real del producto junto a tu mensaje.
• *Reply Buttons* — botones táctiles de 1 toque:
  - Confirmación de pedido: "Sí, lo quiero" / "Ver otros"
  - Método de envío: "Motorizado 🛵" / "Shalom 🚚"
  - Método de pago: "Yape 📱" / "Tarjeta 💳"

No menciones los botones explícitamente; escribe naturalmente y el sistema los agrega.

═══ REGLAS DE ACCIÓN ═══
• Si el cliente dice claramente "lo quiero / lo llevo / sí" sobre un producto identificado → action="collect_variant" y product_id.
• Si ya eligió color y talla y aceptó comprar → action="collect_address" (el sistema lo convertirá a método de envío, no pidas dirección aún).
• Si envió comprobante de pago en texto/imagen → action="payment_received".
• Si está molesto, confundido o pide humano → action="escalate".
• En todos los demás casos → action="none".

═══ FORMATO DE RESPUESTA (JSON ESTRICTO, sin texto fuera del objeto) ═══
{
  "message": "Respuesta natural y corta al cliente.",
  "action": "none|collect_variant|collect_address|escalate|payment_received",
  "product_id": null,
  "client_update": {
    "status": "NUEVO|INTERESADO|CONSULTANDO|ESPERANDO PAGO|PAGO RECIBIDO|NECESITA ASESOR",
    "priority": "ALTA|MEDIA|BAJA",
    "budget_estimate": null,
    "lead_score": 0,
    "tags_to_add": [],
    "tags_to_remove": [],
    "internal_note": null
  }
}
PROMPT;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONVERSATION HISTORY BUILDER
    // ─────────────────────────────────────────────────────────────────────────

    protected function buildConversationHistory(Client $client): array
    {
        $messages = Message::where('client_id', $client->id)
            ->whereNotNull('body')
            ->orderBy('created_at', 'desc')
            ->limit(14)
            ->get()
            ->reverse()
            ->values();

        $raw = $messages->map(fn($m) => [
            'role'  => $m->from_me ? 'model' : 'user',
            'parts' => [['text' => $m->body]],
        ])->toArray();

        // Gemini requires strict alternating turns — merge consecutive same-role
        $clean = [];
        foreach ($raw as $turn) {
            $last = end($clean);
            if ($last && $last['role'] === $turn['role']) {
                $clean[count($clean) - 1]['parts'][0]['text'] .= "\n" . $turn['parts'][0]['text'];
            } else {
                $clean[] = $turn;
            }
        }

        // Must start with 'user'
        if (!empty($clean) && $clean[0]['role'] === 'model') {
            array_shift($clean);
        }

        return $clean;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AI ACTION EXECUTOR
    // ─────────────────────────────────────────────────────────────────────────

    protected function executeAIAction(Client $client, array $parsed, array $state): void
    {
        $action       = $parsed['action'] ?? 'none';
        $productId    = $parsed['product_id'] ?? null;
        $clientUpdate = $parsed['client_update'] ?? [];

        // Apply client status/priority/budget updates from AI
        $updateData = [];
        if (!empty($clientUpdate['status']))         $updateData['status']          = $clientUpdate['status'];
        if (!empty($clientUpdate['priority']))        $updateData['priority']         = $clientUpdate['priority'];
        if (!empty($clientUpdate['budget_estimate'])) $updateData['budget_estimate']  = $clientUpdate['budget_estimate'];
        if (isset($clientUpdate['lead_score']) && Schema::hasColumn('clients', 'lead_score')) {
            $score = (int) $clientUpdate['lead_score'];
            $updateData['lead_score'] = max(0, min(100, $score));
        }

        // ── AI-driven tagging ────────────────────────────────────────────────────────────────────────────────────────────────────────
        $this->syncAiTags($client, $clientUpdate['tags_to_add'] ?? [], $clientUpdate['tags_to_remove'] ?? []);

        // ── AI-driven internal note ───────────────────────────────────────────────────────────────────────────────────────────────────────
        $this->writeAiNote($client, $clientUpdate['internal_note'] ?? null);

        switch ($action) {
            case 'collect_variant':
                $product = $productId ? Product::with('variants')->find($productId) : null;
                if ($product) {
                    $this->sendProductCard($client, $product);
                    $opts = $this->formatVariantsForCustomer($product);
                    $this->reply($client, "¿En qué color y talla lo quieres, hermosa?\n\nLo tengo disponible en {$opts}.\n\nPuedes escribirme por ejemplo: *Negro M* ✨");
                    $state['step']      = 'collect_variant';
                    $state['product_id'] = $product->id;
                    $updateData['state'] = $state;
                }
                break;

            case 'collect_address':
                $state['step']       = 'collect_shipping';
                if ($productId) $state['product_id'] = $productId;
                $updateData['state'] = $state;
                $this->reply($client, "Perfecto, hermosa ✨ ¿Prefieres envío por *Motorizado* o por *Shalom*?");
                $this->sendButtons($client, 'Elige tu método de envío:', ['Motorizado 🛵', 'Shalom 🚚']);
                break;

            case 'payment_received':
                $updateData['status']   = 'PAGO RECIBIDO';
                $updateData['priority'] = 'ALTA';
                break;

            case 'escalate':
                $updateData['status']   = 'NECESITA ASESOR';
                $updateData['priority'] = 'ALTA';
                Log::warning("Client {$client->phone} escalated to human agent.");
                break;

            default:
                // Preserve current state step
                break;
        }

        if (!empty($updateData)) {
            $this->updateClientAndBroadcast($client, $updateData);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VARIANT SELECTION (AI-powered fuzzy match)
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleVariantSelection(Client $client, string $text, array $state): void
    {
        $product = Product::with('variants')->find($state['product_id'] ?? null);

        if (!$product) {
            $this->reply($client, "No encontré el producto. ¿Puedes decirme qué prenda te interesa?");
            $client->update(['state' => ['step' => 'start']]);
            return;
        }

        $variant = $this->matchVariantLocally($text, $product);

        if ($variant && $variant->stock > 0) {
            $state['variant_id']     = $variant->id;
            $state['selected_color'] = $variant->color;
            $state['selected_size']  = $variant->size;
            $state['step']           = 'collect_shipping';
            $client->update(['state' => $state]);
            $this->reply($client,
                "Listo hermosa, te separo el *{$variant->color} talla {$variant->size}* ✨\n\n"
                . "¿Prefieres envío por *Motorizado* o por *Shalom*?"
            );
            $this->sendButtons($client, 'Elige tu método de envío:', ['Motorizado 🛵', 'Shalom 🚚']);
            return;
        }

        if ($variant) {
            $this->reply($client, "Lo siento, hermosa, justo esa opción en *{$variant->color} talla {$variant->size}* está agotada 😔 ¿Eliges otra opción?");
            return;
        }

        $opts = $this->formatVariantsForCustomer($product);
        $this->reply($client, "Ay linda, no entendí bien qué color y talla prefieres 😅\n\nTengo disponible: {$opts}\n\nPor ejemplo puedes escribirme: *Lila S*.");
    }

    /**
     * Local fuzzy matcher for color + size. Avoids a Gemini API call (saves quota + latency).
     */
    protected function matchVariantLocally(string $text, Product $product): ?ProductVariant
    {
        $t = $this->intent->normalize($text);

        // Extract color
        $colorMap = [
            'negro' => 'negro', 'blanco' => 'blanco', 'rojo' => 'rojo',
            'azul' => 'azul', 'celeste' => 'celeste', 'verde' => 'verde',
            'amarillo' => 'amarillo', 'rosa' => 'rosa', 'rosado' => 'rosa',
            'morado' => 'morado', 'lila' => 'lila', 'marron' => 'marrón',
            'marrón' => 'marrón', 'gris' => 'gris', 'beige' => 'beige',
            'crema' => 'crema', 'naranja' => 'naranja',
        ];
        $foundColor = null;
        foreach ($colorMap as $key => $val) {
            if (str_contains($t, $key)) { $foundColor = $val; break; }
        }

        // Extract size
        $foundSize = null;
        if (preg_match('/\b(xs|s|m|l|xl|xxl|2xl)\b/iu', $text, $m)) {
            $foundSize = strtoupper($m[1]);
        } elseif (preg_match('/talla\s+(\d{2,3})/iu', $text, $m)) {
            $foundSize = $m[1];
        }

        // Exact match on color + size
        if ($foundColor && $foundSize) {
            $exact = $product->variants
                ->first(fn($v) => $this->intent->normalize($v->color) === $this->intent->normalize($foundColor)
                    && strtoupper(trim($v->size)) === $foundSize);
            if ($exact) return $exact;
        }

        // Partial: match color only, prefer in-stock
        if ($foundColor) {
            $byColor = $product->variants
                ->first(fn($v) => $this->intent->normalize($v->color) === $this->intent->normalize($foundColor) && $v->stock > 0);
            if ($byColor) return $byColor;
            $byColorAny = $product->variants
                ->first(fn($v) => $this->intent->normalize($v->color) === $this->intent->normalize($foundColor));
            if ($byColorAny) return $byColorAny;
        }

        // Partial: match size only, prefer in-stock
        if ($foundSize) {
            $bySize = $product->variants
                ->first(fn($v) => strtoupper(trim($v->size)) === $foundSize && $v->stock > 0);
            if ($bySize) return $bySize;
            $bySizeAny = $product->variants
                ->first(fn($v) => strtoupper(trim($v->size)) === $foundSize);
            if ($bySizeAny) return $bySizeAny;
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IMAGE ANALYSIS
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleImageMessage(Client $client, ?string $mediaId): void
    {
        $binary = $this->imageService->downloadWhatsAppImage($mediaId);
        if (!$binary) {
            $this->reply($client, "No pude procesar tu imagen 😅 ¿Puedes intentarlo de nuevo?");
            return;
        }

        // ── If the client is awaiting payment, treat the image as a payment receipt.
        $state = $client->state ?? ['step' => 'start'];
        if (in_array($client->status, ['ESPERANDO PAGO', 'VERIFICARYAPE'], true) || ($state['step'] ?? null) === 'awaiting_payment') {
            $this->capturePaymentReceipt($client, $binary);
            return;
        }

        $this->updateClientAndBroadcast($client, ['status' => 'CONSULTANDO', 'priority' => 'MEDIA']);

        $base64  = $this->imageService->toBase64($binary);
        $catalog = $this->searchService->getCatalogForContext();

        $prompt = "Eres una vendedora de Roma Store. El cliente envió una imagen.\n"
            . "Analiza la imagen: ¿qué prenda es? ¿qué estilo, color, modelo?\n"
            . "Compara con este catálogo: {$catalog}\n"
            . "Responde JSON: {\"match\":true,\"product_id\":ID,\"reason\":\"...\",\"message\":\"Respuesta al cliente\"} "
            . "o {\"match\":false,\"message\":\"Respuesta diciendo que no hay algo igual pero ofreciendo alternativa\"}";

        $raw    = $this->geminiService->analyzeImage($base64, $prompt);
        $result = json_decode($this->cleanJsonResponse($raw), true);

        if ($result && ($result['match'] ?? false) && !empty($result['product_id'])) {
            $product = Product::with('variants')->find($result['product_id']);
            if ($product) {
                $this->sendProductInfo($client, $product, $result['message'] ?? null);
                return;
            }
        }

        $message = $result['message'] ?? "¡Qué linda prenda! Déjame buscar algo similar en nuestro catálogo. ¿Puedes decirme qué estilo o color prefieres?";
        $this->reply($client, $message);
        $this->updateClientAndBroadcast($client, ['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ORDER HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    protected function sendProductInfo(Client $client, Product $product, ?string $intro = null): void
    {
        $product->loadMissing('variants');

        $variants = $this->formatVariantsForCustomer($product);

        $prefix = $intro ? $intro . "\n\n" : '';
        $msg    = $prefix
            . "*{$product->name}*\n"
            . "💰 Precio: S/ {$product->price}\n"
            . "📝 {$product->description}\n\n"
            . "Lo tengo disponible en {$variants}.\n\n"
            . "¡Nos confirmas si deseas realizar el pedido para poder ayudarte hermosa! ✨";

        $this->reply($client, $msg);
        $client->update([
            'status'   => 'INTERESADO',
            'priority' => 'ALTA',
            'state'    => ['step' => 'waiting_confirmation', 'product_id' => $product->id],
        ]);
    }

    /**
     * Builds and persists an order in a single transaction.
     * Locks the variant row to prevent overselling on concurrent requests.
     * Supports an optional `quantity` (defaults to 1) coming from state.
     */
    protected function completeOrder(Client $client, array $state): void
    {
        $productId = $state['product_id'] ?? null;
        $variantId = $state['variant_id'] ?? null;
        $quantity  = max(1, (int) ($state['quantity'] ?? 1));

        $product = $productId ? Product::find($productId) : null;
        if (!$product) {
            $this->reply($client, "Hubo un error con tu pedido. Por favor escríbenos nuevamente.");
            return;
        }

        try {
            [$order, $variant] = DB::transaction(function () use ($client, $product, $variantId, $quantity, $state) {
                $variant = $variantId
                    ? ProductVariant::where('id', $variantId)->lockForUpdate()->first()
                    : ProductVariant::where('product_id', $product->id)->orderByDesc('stock')->lockForUpdate()->first();

                if (!$variant) {
                    throw new \RuntimeException('Variant not found for product ' . $product->id);
                }
                if ($variant->stock < $quantity) {
                    throw new \RuntimeException('OUT_OF_STOCK');
                }

                $unitPrice = $product->price;
                if (!empty($product->discount_percent) && $product->discount_percent > 0) {
                    $unitPrice = round($unitPrice * (1 - $product->discount_percent / 100), 2);
                }

                $order = Order::create([
                    'client_id'        => $client->id,
                    'total'            => $unitPrice * $quantity,
                    'shipping_address' => $state['delivery_details'] ?? $state['district'] ?? 'Por confirmar',
                    'shipping_method'  => $state['shipping']  ?? 'Por confirmar',
                    'status'           => 'PAGO RECIBIDO',
                ]);

                OrderItem::create([
                    'order_id'           => $order->id,
                    'product_variant_id' => $variant->id,
                    'quantity'           => $quantity,
                    'price'              => $unitPrice,
                ]);

                $variant->decrement('stock', $quantity);
                $product->increment('sales_count', $quantity);

                if (Schema::hasColumn('clients', 'lifetime_value')) {
                    $client->increment('lifetime_value', $unitPrice * $quantity);
                }

                return [$order, $variant];
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'OUT_OF_STOCK') {
                $this->reply($client,
                    "😔 Justo se acabó el stock de esa opción mientras conversabas. "
                    . "¿Quieres que te muestre alternativas muy parecidas?"
                );
                $this->updateClientAndBroadcast($client, ['state' => ['step' => 'start'], 'status' => 'CONSULTANDO']);
                return;
            }
            Log::error('completeOrder failed: ' . $e->getMessage());
            $this->reply($client, "Tuve un problema al registrar tu pedido 🙅‍♀️. Un asesor te escribirá en unos minutos.");
            $this->updateClientAndBroadcast($client, ['state' => ['step' => 'start'], 'status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
            return;
        } catch (\Throwable $e) {
            Log::error('completeOrder unexpected: ' . $e->getMessage());
            $this->reply($client, "Tuve un problema al registrar tu pedido 🙅‍♀️. Un asesor te escribirá en unos minutos.");
            $this->updateClientAndBroadcast($client, ['state' => ['step' => 'start'], 'status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
            return;
        }

        $recipientName  = $client->name ?? 'hermosa';
        $variantDetails = " ({$variant->color}, talla {$variant->size})";
        $qtyDetails     = $quantity > 1 ? " × {$quantity}" : '';
        $orderNum       = str_pad($order->id, 5, '0', STR_PAD_LEFT);

        $this->reply($client,
            "🎉 *¡Pedido #{$orderNum} registrado, {$recipientName}!*\n\n"
            . "Tu *{$product->name}{$variantDetails}{$qtyDetails}* quedó separado y programado, linda.\n"
            . "Método de envío: *" . ($state['shipping'] ?? 'Por confirmar') . "*.\n\n"
            . "Gracias por tu compra, hermosa 💕"
        );

        $this->updateClientAndBroadcast($client, ['status' => 'PAGO RECIBIDO', 'priority' => 'ALTA', 'state' => ['step' => 'start']]);

        // Cross-sell natural post-pedido.
        $this->sendUpsellSuggestion($client, $product);
    }

    /**
     * After a successful order, suggest one complementary product (same category,
     * top-selling, with stock). Keeps it subtle to avoid feeling spammy.
     */
    protected function sendUpsellSuggestion(Client $client, Product $justBought): void
    {
        $similar = $this->searchService->getSimilar($justBought->id, 2);
        if ($similar->isEmpty()) return;

        $first = $similar->first();
        $price = !empty($first->discount_percent) && $first->discount_percent > 0
            ? round($first->price * (1 - $first->discount_percent / 100), 2)
            : $first->price;

        $this->reply($client,
            "Aprovechando tu envío, muchas clientas se están llevando también *{$first->name}* "
            . "por *S/ " . number_format($price, 2) . "*. "
            . "Si lo sumas ahora, va en el mismo paquete sin costo extra de envío. ¿Te lo agrego?"
        );
    }

    /**
     * Persists a payment receipt image and marks the most recent pending order
     * for the client as PAGO RECIBIDO (pending human verification).
     */
    protected function capturePaymentReceipt(Client $client, string $binary): void
    {
        try {
            $filename = sprintf('payments/%s_%s.jpg', $client->id, now()->format('YmdHis'));
            Storage::disk('public')->put($filename, $binary);
            $url = '/storage/' . $filename;
            $state = $client->state ?? ['step' => 'start'];
            $expectedAmount = $this->getExpectedPaymentAmount($state);
            $detectedAmount = $this->extractPaymentAmountFromReceipt($binary);

            if ($detectedAmount !== null && $detectedAmount + 0.01 < $expectedAmount) {
                $missing = $expectedAmount - $detectedAmount;
                $this->reply($client,
                    "¡Ay, hermosa! Veo que te equivocaste en el monto del Yape 😥\n\n"
                    . "Me llegó por *S/ " . number_format($detectedAmount, 2) . "* pero el pedido está en *S/ " . number_format($expectedAmount, 2) . "*.\n\n"
                    . "Completa la diferencia de *S/ " . number_format($missing, 2) . "* al mismo número para poder dejarlo programado por ti, linda. 💕"
                );
                return;
            }

            if ($detectedAmount === null) {
                $this->reply($client, "Recibí tu captura, hermosa. La voy a pasar a revisión para confirmar el monto y dejar tu pedido programado. 💕");
                $this->updateClientAndBroadcast($client, [
                    'status' => 'VERIFICARYAPE',
                    'priority' => 'ALTA',
                    'payment_receipt_url' => $url,
                    'paid_amount' => null,
                ]);
                return;
            }

            $order = Order::where('client_id', $client->id)
                ->whereIn('status', ['PENDIENTE', 'ESPERANDO PAGO'])
                ->latest()->first();

            if ($order) {
                $order->update([
                    'payment_receipt_url' => $url,
                    'status'              => 'PAGO RECIBIDO',
                ]);
            }

            $state['payment_receipt_url'] = $url;
            $state['paid_amount'] = $detectedAmount;

            if (($state['shipping'] ?? null) === 'Motorizado') {
                $state['step'] = 'collect_delivery_details_motorizado';
                $client->update(['state' => $state]);
                $this->updateClientAndBroadcast($client, [
                    'status' => 'PAGO RECIBIDO',
                    'priority' => 'ALTA',
                    'payment_receipt_url' => $url,
                    'paid_amount' => $detectedAmount,
                ]);
                $this->reply($client,
                    "¡Pago recibido, hermosa! ✅\n\n"
                    . "Para programar tu envío por motorizado, envíame estos datos en un solo mensaje:\n\n"
                    . "Nombre completo, celular, dirección escrita y tu ubicación en tiempo real. 🛵"
                );
                return;
            }

            $state['step'] = 'collect_delivery_details_shalom';
            $client->update(['state' => $state]);
            $this->updateClientAndBroadcast($client, [
                'status' => 'PAGO RECIBIDO',
                'priority' => 'ALTA',
                'payment_receipt_url' => $url,
                'paid_amount' => $detectedAmount,
            ]);
            $this->reply($client,
                "¡Pago recibido, hermosa! ✅\n\n"
                . "Para enviarlo por Shalom, envíame estos datos en un solo mensaje:\n\n"
                . "Nombre completo, DNI, celular y sede exacta de Shalom. 🚚"
            );
        } catch (\Throwable $e) {
            Log::error('capturePaymentReceipt failed: ' . $e->getMessage());
            $this->reply($client, "Recibí tu comprobante pero hubo un problema técnico al guardarlo 😅. Un asesor lo revisará personalmente en breve.");
            $this->updateClientAndBroadcast($client, ['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
        }
    }

    /**
     * Apply tag additions / removals decided by the AI.
     * - Only allows tags that already exist in the DB (the AI was told the exact list).
     * - Idempotent: skip tags the client already has / doesn't have.
     */
    protected function syncAiTags(Client $client, array $toAdd, array $toRemove): void
    {
        $toAdd    = array_filter(array_map('trim', $toAdd));
        $toRemove = array_filter(array_map('trim', $toRemove));

        if (empty($toAdd) && empty($toRemove)) return;

        try {
            $currentIds = $client->tags->pluck('id')->all();

            if (!empty($toAdd)) {
                $addIds = Tag::whereIn('name', $toAdd)->pluck('id')->all();
                $newIds = array_diff($addIds, $currentIds);
                if (!empty($newIds)) {
                    $client->tags()->attach($newIds);
                }
            }

            if (!empty($toRemove)) {
                $removeIds = Tag::whereIn('name', $toRemove)->pluck('id')->all();
                $removeIds = array_intersect($removeIds, $currentIds);
                if (!empty($removeIds)) {
                    $client->tags()->detach($removeIds);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('syncAiTags failed: ' . $e->getMessage());
        }
    }

    /**
     * Persist an internal note suggested by the AI.
     * Guards: ignore empty/whitespace, ignore if the same text was added in the
     * last hour (anti-spam), cap length at 240 chars.
     */
    protected function writeAiNote(Client $client, ?string $body): void
    {
        $body = is_string($body) ? trim($body) : '';
        if ($body === '' || mb_strlen($body) < 5) return;

        $body = mb_substr($body, 0, 240);

        try {
            $duplicate = ClientNote::where('client_id', $client->id)
                ->whereNull('user_id') // AI-authored
                ->where('body', $body)
                ->where('created_at', '>=', now()->subHour())
                ->exists();

            if ($duplicate) return;

            ClientNote::create([
                'client_id' => $client->id,
                'user_id'   => null, // null = autored by the AI
                'body'      => $body,
            ]);
        } catch (\Throwable $e) {
            Log::warning('writeAiNote failed: ' . $e->getMessage());
        }
    }

    protected function findDeliveryZoneFromText(string $text): ?DeliveryZone
    {
        $direct = DeliveryZone::findByName($text);
        if ($direct) return $direct;

        foreach (DeliveryZone::where('active', true)->get() as $zone) {
            if (str_contains($this->intent->normalize($text), $this->intent->normalize($zone->district))) {
                return $zone;
            }
        }

        return null;
    }

    protected function getOrderProductTotal(array $state): float
    {
        $product = !empty($state['product_id']) ? Product::find($state['product_id']) : null;
        if (!$product) return 89.00;

        $price = (float) $product->price;
        if (!empty($product->discount_percent) && $product->discount_percent > 0) {
            $price = round($price * (1 - $product->discount_percent / 100), 2);
        }

        return $price * max(1, (int) ($state['quantity'] ?? 1));
    }

    protected function getExpectedPaymentAmount(array $state): float
    {
        $amount = $this->getOrderProductTotal($state);
        if (($state['shipping'] ?? null) === 'Shalom') {
            $amount += (float) ($state['shipping_cost'] ?? IntentDetector::getShalomLima());
        }

        return round($amount, 2);
    }

    protected function extractPaymentAmountFromReceipt(string $binary): ?float
    {
        $base64 = $this->imageService->toBase64($binary);
        $prompt = "Analiza esta imagen como comprobante de Yape/Plin. Devuelve SOLO JSON estricto: "
            . "{\"is_receipt\":true|false,\"amount\":numero|null}. "
            . "Si no puedes leer el monto con claridad, usa amount:null.";

        $raw = $this->geminiService->analyzeImage($base64, $prompt);
        $data = json_decode($this->cleanJsonResponse($raw), true);

        if (!is_array($data) || empty($data['is_receipt']) || !isset($data['amount']) || !is_numeric($data['amount'])) {
            return null;
        }

        return round((float) $data['amount'], 2);
    }

    protected function finalizePaidOrder(Client $client, array $state): void
    {
        if (empty(trim($state['delivery_details'] ?? ''))) {
            $this->reply($client, "Hermosa, necesito los datos completos para programar tu pedido por favor 💕");
            return;
        }

        $this->completeOrder($client, $state);
    }

    protected function formatVariantsForCustomer(Product $product): string
    {
        $available = $product->variants->where('stock', '>', 0);
        if ($available->isEmpty()) {
            return "por ahora sin stock disponible";
        }

        return $available
            ->groupBy('color')
            ->map(function ($variants, $color) {
                $sizes = $variants->pluck('size')->unique()->sort()->values()->implode(', ');
                return "{$color} en tallas {$sizes}";
            })
            ->values()
            ->join('; ');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UTILITIES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update client fields AND broadcast a ClientStatusUpdated event so the CRM dashboard
     * refreshes in realtime. Use this whenever the bot changes the client's status/priority.
     */
    protected function updateClientAndBroadcast(Client $client, array $data): void
    {
        $client->update($data);
        // Only broadcast when status or priority changed (avoids noise on state-only updates)
        if (array_key_exists('status', $data) || array_key_exists('priority', $data)) {
            broadcast(new ClientStatusUpdated($client->fresh()))->toOthers();
        }
    }

    protected function reply(Client $client, string $body): void
    {
        $this->whatsAppService->sendMessage($client->phone, $body);

        $msg = Message::create([
            'client_id' => $client->id,
            'from_me'   => true,
            'body'      => $body,
            'type'      => 'text',
        ]);

        broadcast(new MessageReceived($msg))->toOthers();

        // SLA: stamp first response time only once.
        $this->touchFirstResponseAt($client);
    }

    /**
     * Send WhatsApp interactive reply buttons (max 3, 20 chars each title).
     */
    protected function sendButtons(Client $client, string $bodyText, array $buttonTitles): void
    {
        $buttons = [];
        foreach ($buttonTitles as $idx => $title) {
            $buttons[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => 'btn_' . $idx,
                    'title' => mb_substr($title, 0, 20),
                ],
            ];
        }

        $this->whatsAppService->sendInteractiveButtons($client->phone, $bodyText, $buttons);

        $msg = Message::create([
            'client_id' => $client->id,
            'from_me'   => true,
            'body'      => $bodyText . "\n\n[Botones: " . implode(', ', $buttonTitles) . ']',
            'type'      => 'text',
        ]);

        broadcast(new MessageReceived($msg))->toOthers();
    }

    /**
     * Send a WhatsApp List Message with all product categories.
     */
    protected function sendCategoryList(Client $client): void
    {
        $categories = Category::orderBy('name')->get();
        if ($categories->isEmpty()) {
            $this->reply($client, "Aún estamos organizando el catálogo 📦. Pregúntame por lo que buscas y te ayudo.");
            return;
        }

        $rows = $categories->map(fn($c) => [
            'id'          => 'cat_' . $c->id,
            'title'       => mb_substr($c->name, 0, 24),
            'description' => mb_substr('Ver productos de ' . $c->name, 0, 72),
        ])->values()->all();

        // WhatsApp List Message limits: max 10 sections, max 10 rows per section
        $sections = [['title' => 'Categorías', 'rows' => $rows]];

        $this->whatsAppService->sendListMessage(
            $client->phone,
            "Elige una categoría para ver los productos disponibles 👇",
            'Ver categorías',
            $sections
        );

        $msg = Message::create([
            'client_id' => $client->id,
            'from_me'   => true,
            'body'      => "Elige una categoría para ver los productos disponibles 👇\n\n[Categorías: " . $categories->pluck('name')->join(', ') . ']',
            'type'      => 'text',
        ]);

        broadcast(new MessageReceived($msg))->toOthers();
    }

    /**
     * Send a visual product card with image header + action buttons.
     * Simulates Single Product Message without Meta Commerce Catalog.
     */
    protected function sendProductCard(Client $client, Product $product): void
    {
        $price = $product->discount_percent > 0
            ? round($product->price * (1 - $product->discount_percent / 100), 2)
            : $product->price;

        $priceText = $product->discount_percent > 0
            ? "~S/ " . number_format($product->price, 2) . "~ → *S/ " . number_format($price, 2) . "* 🔥"
            : "S/ " . number_format($price, 2);

        $variantSummary = $this->formatVariantsForCustomer($product);

        $body = "*{$product->name}*\n"
              . "{$priceText}\n"
              . ($product->description ? mb_strimwidth($product->description, 0, 80, '…') . "\n" : '')
              . "Disponible en {$variantSummary}";

        $buttons = [
            ['type' => 'reply', 'reply' => ['id' => 'buy_' . $product->id, 'title' => 'Lo quiero 💚']],
            ['type' => 'reply', 'reply' => ['id' => 'colors_' . $product->id, 'title' => 'Ver colores']],
        ];

        $this->whatsAppService->sendProductCard(
            $client->phone,
            $product->name,
            $body,
            $product->image_url,
            $buttons
        );

        $msg = Message::create([
            'client_id' => $client->id,
            'from_me'   => true,
            'body'      => $body . "\n\n[Producto: {$product->name} - Imagen: {$product->image_url}]",
            'type'      => 'text',
        ]);

        broadcast(new MessageReceived($msg))->toOthers();
    }

    protected function cleanJsonResponse(?string $text): string
    {
        if (empty($text)) return '{}';

        $text = trim($text);

        // Strip markdown code fences
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i',           '', $text);
        $text = trim($text);

        // Return if already valid JSON
        if (json_decode($text) !== null) {
            return $text;
        }

        // Gemini sometimes outputs text BEFORE or AFTER the JSON block.
        // Find the first complete {...} object inside the string.
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            if (json_decode($candidate) !== null) {
                return $candidate;
            }
        }

        return '{}';
    }
}
