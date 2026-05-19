<?php

namespace App\Services\Utility;

use App\Events\ClientStatusUpdated;
use App\Events\MessageReceived;
use App\Support\SafeBroadcast;
use App\Models\Client;
use App\Models\DeliveryZone;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Services\AI\AIPromptService;
use App\Services\AI\GeminiService;
use App\Services\AI\IntentService;
use App\Services\Business\ClientService;
use App\Services\Business\DeliveryService;
use App\Services\Business\OrderService;
use App\Services\Business\PaymentService;
use App\Services\Business\ProductSearchService;
use App\Services\Business\VariantSelectionService;
use App\Services\Messaging\WhatsAppMessageService;
use App\Services\Messaging\WhatsAppService;
use App\Services\State\ClientStateMachine;
use App\Services\Utility\ImageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class MessageHandler
{
    protected WhatsAppService    $whatsAppService;
    protected ClientStateMachine $clientStateMachine;
    protected ClientService      $clientService;
    protected DeliveryService    $deliveryService;
    protected GeminiService      $geminiService;
    protected ImageService       $imageService;
    protected IntentService       $intent;
    protected OrderService       $orderService;
    protected PaymentService     $paymentService;
    protected ProductSearchService $searchService;
    protected VariantSelectionService $variantSelectionService;
    protected WhatsAppMessageService $whatsappMessageService;
    protected AIPromptService $aiPromptService;

    public function __construct(
        WhatsAppService    $whatsAppService,
        ClientStateMachine $clientStateMachine,
        ClientService      $clientService,
        DeliveryService    $deliveryService,
        GeminiService      $geminiService,
        ImageService       $imageService,
        OrderService       $orderService,
        PaymentService     $paymentService,
        ProductSearchService $searchService,
        IntentService       $intent,
        VariantSelectionService $variantSelectionService,
        WhatsAppMessageService $whatsappMessageService,
        AIPromptService $aiPromptService
    ) {
        $this->whatsAppService          = $whatsAppService;
        $this->clientStateMachine      = $clientStateMachine;
        $this->clientService           = $clientService;
        $this->deliveryService         = $deliveryService;
        $this->geminiService           = $geminiService;
        $this->imageService            = $imageService;
        $this->orderService            = $orderService;
        $this->paymentService          = $paymentService;
        $this->searchService           = $searchService;
        $this->intent                  = $intent;
        $this->variantSelectionService = $variantSelectionService;
        $this->whatsappMessageService  = $whatsappMessageService;
        $this->aiPromptService        = $aiPromptService;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENTRY POINT
    // ─────────────────────────────────────────────────────────────────────────

    public function process($messageData, $contactData): void
    {
        $phone     = $messageData['from'];
        $name      = is_array($contactData) ? ($contactData['profile']['name'] ?? null) : null;
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

            SafeBroadcast::event(new MessageReceived($msg));
            SafeBroadcast::event(new ClientStatusUpdated($client->fresh()));

            $client->update([
                'last_interaction_at' => now(),
                'last_customer_message_at' => now(),
            ]);

            if ($client->bot_paused_at !== null) {
                Log::info("Message saved, but bot is paused for {$client->phone}. Ignoring.");
                $this->whatsappMessageService->markAsRead($messageId);
                return;
            }

            if ($type === 'image') {
                $this->handleImageMessage($client, $mediaId);
            } elseif ($type === 'text') {
                $this->handleTextMessage($client, $body);
            } elseif (in_array($type, ['audio', 'video', 'document', 'sticker'], true)) {
                $this->whatsappMessageService->reply($client,
                    "Hermosa, por ahora solo puedo leer *texto* e *imágenes* de prendas 😊 "
                    . "¿Me escribes qué buscas o me envías una foto del modelo?"
                );
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

                if (str_starts_with($btnId, 'prod_')) {
                    $pid = (int) str_replace('prod_', '', $btnId);
                    $product = $this->searchService->findAvailable($pid);
                    if ($product) {
                        $this->presentProductFromCatalog($client, $product);
                        $this->whatsappMessageService->markAsRead($messageId);
                        return;
                    }
                }

                if (str_starts_with($btnId, 'buy_')) {
                    $this->handleTextMessage($client, $option);
                    $this->whatsappMessageService->markAsRead($messageId);
                    return;
                }

                $this->handleTextMessage($client, $option);
            }

            $this->whatsappMessageService->markAsRead($messageId);
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
            $this->whatsappMessageService->reply($client, "Entendido, cancelé el proceso. 😊 ¿Hay algo más en que pueda ayudarte?");
            $this->clientService->updateClientAndBroadcast($client, [
                'status' => 'INTERESADO',
                'opted_out_at' => now(),
                'confirmation_requested_at' => null,
                'followup_3_sent_at' => null,
                'followup_15_sent_at' => null,
                'followup_stopped_at' => null,
            ]);
            ClientStateMachine::resetFlow($client);
            return;
        }

        // ── Payment method selection (Yape vs Tarjeta) ───────────────────────
        // If the client is awaiting payment and picks a method, send method-specific info.
        if ($client->status === 'ESPERANDO PAGO') {
            $payment = $this->paymentService->detectPaymentMethod($cleanText);
            if ($payment === 'yape') {
                ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_AWAITING_PAYMENT, ['payment_method' => 'Yape']);
                $this->whatsappMessageService->reply($client, $this->paymentService->buildYapeOnlyMessage($this->paymentService->getExpectedPaymentAmount($client->state)));
                return;
            }
            if ($payment === 'tarjeta') {
                ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_AWAITING_PAYMENT, ['payment_method' => 'Tarjeta']);
                $this->whatsappMessageService->reply($client, $this->paymentService->buildCardOnlyMessage($this->paymentService->getExpectedPaymentAmount($client->state)));
                
                // Escalate to human to generate link
                $this->clientService->updateClientAndBroadcast($client, ['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
                $usersToNotify = \App\Models\User::all();
                \Illuminate\Support\Facades\Notification::send($usersToNotify, new \App\Notifications\HumanRequestedNotification($client));
                Log::info("Client {$client->phone} requested Tarjeta/Link. Escapated to human.");
                return;
            }
        }

        $currentStep = $state['step'] ?? 'start';
        $productIdFromState = $state['product_id'] ?? null;

        // Confirmación de pedido → elegir variante (sin IA)
        if ($currentStep === ClientStateMachine::STEP_WAITING_CONFIRMATION && $productIdFromState) {
            if ($this->intent->looksLikeRejection($cleanText)) {
                ClientStateMachine::resetFlow($client);
                $this->whatsappMessageService->sendCategoryList($client);
                return;
            }
            if ($this->intent->looksLikeOrderConfirmation($cleanText) || $this->intent->looksLikeBuyIntent($cleanText)) {
                $this->startVariantSelection($client, (int) $productIdFromState);
                return;
            }
        }

        // "Lo quiero" con producto ya en contexto
        if ($productIdFromState && $this->intent->looksLikeBuyIntent($cleanText)) {
            $this->startVariantSelection($client, (int) $productIdFromState);
            return;
        }

        // Simple data-collection steps (no AI needed — just store the value)
        $dataStep = $state['step'] ?? 'start';
        if ($dataStep === 'collect_delivery_district') {
            $zone = $this->deliveryService->findDeliveryZoneFromText($text);
            if (!$zone) {
                $this->whatsappMessageService->reply($client, "Hermosa, ¿me confirmas el distrito exacto de Lima para cotizarte el delivery por motorizado? 🛵");
                return;
            }
            $state['district'] = $zone->district;
            $state['delivery_cost'] = (float) $zone->motorizado_cost;
            ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_COLLECT_PAYMENT_METHOD, $state);
            $this->whatsappMessageService->reply($client,
                $this->deliveryService->buildMotorizadoMessage($zone, $this->paymentService->getExpectedPaymentAmount($state))
            );
            $this->whatsappMessageService->sendButtons($client, '¿Cómo prefieres pagar?', ['Yape 📱', 'Tarjeta 💳']);
            return;
        }
        if ($dataStep === 'collect_payment_method') {
            $payment = $this->paymentService->detectPaymentMethod($cleanText);
            if (!$payment) {
                $this->whatsappMessageService->reply($client, "Claro hermosa, para dejarlo separado dime si prefieres pagar por *Yape* o por *tarjeta/link* ✨");
                $this->whatsappMessageService->sendButtons($client, '¿Cómo prefieres pagar?', ['Yape 📱', 'Tarjeta 💳']);
                return;
            }
            $state['payment_method'] = $payment === 'yape' ? 'Yape' : 'Tarjeta';
            ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_AWAITING_PAYMENT, $state);
            
            if ($payment === 'tarjeta') {
                $this->clientService->updateClientAndBroadcast($client, ['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
                $usersToNotify = \App\Models\User::all();
                \Illuminate\Support\Facades\Notification::send($usersToNotify, new \App\Notifications\HumanRequestedNotification($client));
                $this->whatsappMessageService->reply($client, $this->paymentService->buildCardOnlyMessage($this->paymentService->getExpectedPaymentAmount($client->state)));
                Log::info("Client {$client->phone} requested Tarjeta/Link. Escapated to human.");
            } else {
                $this->clientService->updateClientAndBroadcast($client, ['status' => 'ESPERANDO PAGO', 'priority' => 'ALTA']);
                $this->whatsappMessageService->reply($client, $this->paymentService->buildYapeOnlyMessage($this->paymentService->getExpectedPaymentAmount($client->state)));
            }
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
            $shippingChoice = $this->deliveryService->normalizeShippingChoice($text);
            if (!$shippingChoice) {
                $this->whatsappMessageService->reply($client,
                    "Hermosa, para avanzar necesito que elijas una opción de envío válida 😊\n\n"
                    . "Puede ser *Motorizado* o *Shalom*."
                );
                $this->whatsappMessageService->sendButtons($client, 'Elige tu método de envío:', ['Motorizado 🛵', 'Shalom 🚚']);
                return;
            }
            $state['shipping'] = $shippingChoice;
            if ($shippingChoice === 'Motorizado') {
                ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_COLLECT_DELIVERY_DISTRICT, $state);
                $this->whatsappMessageService->reply($client, "Perfecto, hermosa 🛵 ¿A qué distrito sería el envío?");
                return;
            }
            $state['shipping_cost'] = $this->deliveryService->getShalomCost();
            ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_COLLECT_PAYMENT_METHOD, $state);
            $this->whatsappMessageService->reply($client,
                $this->deliveryService->buildShalomMessage($this->paymentService->getExpectedPaymentAmount($state))
            );
            $this->whatsappMessageService->sendButtons($client, '¿Cómo prefieres pagar?', ['Yape 📱', 'Tarjeta 💳']);
            return;
        }

        // Variant selection (uses AI for fuzzy matching)
        if ($dataStep === 'collect_variant') {
            $this->handleVariantSelection($client, $text, $state);
            return;
        }

        // Respuestas locales (sin Gemini) en consulta general
        if ($this->canUseQuickIntents($dataStep) && ($intent = $this->intent->detect($text)) !== null) {
            if ($this->handleQuickIntent($client, $intent)) {
                return;
            }
        }

        // Búsqueda en inventario antes de llamar a Gemini
        if ($this->canUseQuickIntents($dataStep) && $this->tryInventorySearchReply($client, $text, $state)) {
            return;
        }

        $productIdFromState = $state['product_id'] ?? null;
        if ($productIdFromState && $this->intent->looksLikePhotoRequest($text)) {
            $this->whatsappMessageService->sendProductPhoto($client, $productIdFromState);
            return;
        }

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
                $this->whatsappMessageService->reply($client,
                    "¡Hola{$name}! 👋 Soy *Roma*, asesora de *Roma Store* — tu tienda de ropa peruana.\n\n"
                    . "¿Qué estás buscando hoy? Puedo mostrarte:\n\n"
                    . "👕 Polos y poleras\n"
                    . "🧥 Casacas y chompas\n"
                    . "👖 Pantalones y jeans\n\n"
                    . "O escríbeme directamente lo que te interesa (color, talla, presupuesto)."
                );
                if (in_array($client->status, ['NUEVO', null], true)) {
                    $this->clientService->updateClientAndBroadcast($client, ['status' => 'INTERESADO']);
                }
                return true;

            case 'escalate':
                $this->whatsappMessageService->reply($client,
                    "¡Claro! 🙌 En unos minutos un asesor del equipo te escribe por aquí mismo. "
                    . "Mientras tanto, si quieres ir adelantando, dime qué prenda te interesa."
                );
                $this->clientService->updateClientAndBroadcast($client, ['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
                
                // Notify agents
                $usersToNotify = \App\Models\User::all();
                \Illuminate\Support\Facades\Notification::send($usersToNotify, new \App\Notifications\HumanRequestedNotification($client));
                
                Log::info("Client {$client->phone} requested human (intent detector). Notified agents.");
                return true;

            case 'business_hours':
                $this->whatsappMessageService->reply($client,
                    "🕒 Nuestro horario de atención por WhatsApp:\n\n*" . IntentService::getBusinessHours() . "*\n\n"
                    . "Pero puedes escribirnos cuando quieras y te respondo en cuanto abramos. 😊"
                );
                return true;

            case 'store_location':
                $this->whatsappMessageService->reply($client,
                    "📍 Somos una tienda *online* (vendemos por *TikTok Live* y *WhatsApp*) con envíos a todo el Perú:\n\n"
                    . "🏍️ *Lima*: delivery motorizado en el día.\n"
                    . "📦 *Provincias*: por Shalom (1–3 días).\n\n"
                    . "¿Quieres ver el catálogo? Dime qué buscas."
                );
                return true;

            case 'payment_info':
                $this->whatsappMessageService->reply($client, $this->clientService->buildPaymentInfoMessage());
                return true;

            case 'delivery_quote':
                $zone = DeliveryZone::find($intent['zone_id'] ?? null);
                if ($zone) {
                    $this->whatsappMessageService->reply($client,
                        "🏍️ *Delivery a {$zone->district}*:\n\n"
                        . "• *Motorizado*: S/ " . number_format($zone->motorizado_cost, 2) . " (" . IntentService::getMotorizadoWindow() . ")\n"
                        . "• *Shalom Lima*: S/ " . number_format($zone->shalom_cost, 2) . "\n\n"
                        . "¿Qué método prefieres, hermosa? 💛"
                    );
                    return true;
                }
                return false;

            case 'delivery_info':
                $this->whatsappMessageService->reply($client,
                    "📦 *Opciones de envío:*\n\n"
                    . "🏍️ *Motorizado* (Lima): tarifa según distrito — " . IntentService::getMotorizadoWindow() . ".\n"
                    . "📦 *Shalom Lima*: S/ " . IntentService::getShalomLima() . ".\n"
                    . "📦 *Shalom Provincia*: ~S/ " . IntentService::getShalomProvincia() . " en promedio.\n\n"
                    . "¿A qué distrito te lo enviamos? Así te paso el costo exacto."
                );
                return true;

            case 'catalog_request':
                $this->whatsappMessageService->sendCategoryList($client);
                $top = $this->searchService->getTopSelling(5);
                if ($top->isNotEmpty()) {
                    $list = $top->map(fn($p) => '• *' . $p->name . '* — S/ ' . number_format($p->price, 2))->join("\n");
                    $this->whatsappMessageService->reply($client,
                        "🔥 *Lo más vendido esta semana:*\n\n{$list}\n\n"
                        . "Dime cuál te llama la atención (o mándame foto de algo similar) y te paso colores, tallas y stock."
                    );
                }
                $this->clientService->updateClientAndBroadcast($client, ['status' => 'INTERESADO']);
                return true;

            case 'thanks':
                $this->whatsappMessageService->reply($client, "¡Gracias a ti! 💛 Cualquier cosa estoy por aquí.");
                return true;
        }

        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CORE: CONTEXTUAL AI ENGINE
    // ─────────────────────────────────────────────────────────────────────────

    protected function processWithContextualAI(Client $client, string $text, array $state): void
    {
        $history   = $this->aiPromptService->buildConversationHistory($client);
        $history[] = ['role' => 'user', 'parts' => [['text' => $text]]];

        $systemPrompt = $this->aiPromptService->buildSystemPrompt($client, $state, $text);
        $raw          = $this->geminiService->chatWithHistory($history, $systemPrompt);

        if (!$raw) {
            $err = $this->geminiService->lastErrorCode;

            // Try local intent handling when Gemini is offline (quota/auth error).
            // This keeps the bot responsive for greetings, catalog, FAQs, etc.
            if (in_array($err, ['quota', 'auth', 'error'], true)) {
                Log::warning("Gemini unavailable ({$err}) for client {$client->phone}, using local fallback.");

                $localIntent = $this->intent->detect($text);
                if ($localIntent && $this->handleQuickIntent($client, $localIntent)) {
                    return;
                }

                if ($this->tryInventorySearchReply($client, $text, $state)) {
                    return;
                }

                $this->replyWithInventoryCatalog($client);
                return;
            }

            $this->replyWithInventoryCatalog($client);
            return;
        }

        $parsed = json_decode($this->aiPromptService->cleanJsonResponse($raw), true);

        // If JSON parse fails, send raw text as fallback
        if (!$parsed || !isset($parsed['message'])) {
            $this->whatsappMessageService->reply($client, $raw);
            return;
        }

        $this->whatsappMessageService->reply($client, $parsed['message']);

        $productIdFromParsed = isset($parsed['product_id']) ? (int) $parsed['product_id'] : null;
        $productIdFromState = $state['product_id'] ?? $productIdFromParsed;
        if ($productIdFromState && ($this->intent->looksLikePhotoRequest($parsed['message']) || ($parsed['action'] ?? null) === 'show_photo')) {
            $this->whatsappMessageService->sendProductPhoto($client, (int) $productIdFromState);
        }

        $armConfirmation = $this->aiPromptService->executeAIAction(
            $client,
            $parsed,
            $state,
            fn ($method, $args) => $this->whatsappMessageService->$method(...$args)
        );

        if ($armConfirmation || $this->intent->looksLikeConfirmationRequest($parsed['message'])) {
            if (!in_array($client->fresh()->status, ['ESPERANDO PAGO', 'PAGO RECIBIDO', 'EN PREPARACIÓN', 'FINALIZADO', 'ABANDONADO'], true)) {
                $client->update([
                    'confirmation_requested_at' => now(),
                    'followup_3_sent_at' => null,
                    'followup_15_sent_at' => null,
                    'followup_stopped_at' => null,
                ]);
            }
        }

        if (($parsed['action'] ?? null) === 'escalate') {
            return;
        }

        // Clear one-time category filter
        if (!empty($state['category_id'])) {
            $freshState = $client->fresh()->state ?? [];
            unset($freshState['category_id']);
            $client->update(['state' => $freshState]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VARIANT SELECTION (AI-powered fuzzy match)
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleVariantSelection(Client $client, string $text, array $state): void
    {
        $product = Product::with('variants')->find($state['product_id'] ?? null);

        if (!$product) {
            $this->whatsappMessageService->reply($client, "No encontré el producto. ¿Puedes decirme qué prenda te interesa?");
            ClientStateMachine::resetFlow($client);
            return;
        }

        $variant = $this->variantSelectionService->matchVariantFromText($product, $text);

        if ($variant && $variant->stock > 0) {
            $state['variant_id']     = $variant->id;
            $state['selected_color'] = $variant->color;
            $state['selected_size']  = $variant->size;
            ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_COLLECT_SHIPPING, $state);
            $state = $client->fresh()->state ?? $state;
            $this->whatsappMessageService->reply($client,
                "Listo hermosa, te separo el *{$variant->color} talla {$variant->size}* ✨\n\n"
                . "¿Prefieres envío por *Motorizado* o por *Shalom*?"
            );
            $this->whatsappMessageService->sendButtons($client, 'Elige tu método de envío:', ['Motorizado 🛵', 'Shalom 🚚']);
            return;
        }

        if ($variant) {
            $this->whatsappMessageService->reply($client, "Lo siento, hermosa, justo esa opción en *{$variant->color} talla {$variant->size}* está agotada 😔 ¿Eliges otra opción?");
            return;
        }

        $opts = $this->variantSelectionService->formatVariantsForCustomer($product);
        $this->whatsappMessageService->reply($client, "Ay linda, no entendí bien qué color y talla prefieres 😅\n\nTengo disponible: {$opts}\n\nPor ejemplo puedes escribirme: *Lila S*.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IMAGE ANALYSIS
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleImageMessage(Client $client, ?string $mediaId): void
    {
        $binary = $this->imageService->downloadWhatsAppImage($mediaId);
        if (!$binary) {
            $this->whatsappMessageService->reply($client, "No pude procesar tu imagen 😅 ¿Puedes intentarlo de nuevo?");
            return;
        }

        // ── If the client is awaiting payment, treat the image as a payment receipt.
        $state = $client->state ?? ['step' => 'start'];
        if (in_array($client->status, ['ESPERANDO PAGO', 'VERIFICARYAPE'], true) || ($state['step'] ?? null) === 'awaiting_payment') {
            $this->capturePaymentReceipt($client, $binary);
            return;
        }

        $this->clientService->updateClientAndBroadcast($client, ['status' => 'CONSULTANDO', 'priority' => 'MEDIA']);

        $base64 = $this->imageService->toBase64($binary);

        $prompt = "Analiza la prenda en la imagen. Responde SOLO JSON:\n"
            . "{\"color\":\"\",\"category\":\"polo|vestido|pantalon|casaca|otro\",\"name\":\"palabra clave\",\"keywords\":[\"\",\"\"]}\n"
            . "No inventes marcas. Si no ves bien la prenda, keywords vacío.";

        $raw = $this->geminiService->analyzeImage($base64, $prompt);
        $attributes = json_decode($this->aiPromptService->cleanJsonResponse($raw), true) ?: [];

        $matches = $this->searchService->searchFromImageAttributes($attributes);

        if ($matches->count() === 1) {
            $product = $matches->first();
            $this->presentProductFromCatalog($client, $product, '¡Encontré algo muy parecido en nuestro inventario! ✨');
            return;
        }

        if ($matches->count() > 1) {
            $list = $this->searchService->formatProductListForChat($matches->take(5));
            $this->whatsappMessageService->reply($client,
                "¡Qué linda referencia! 💕 Tengo estas opciones *con stock* en nuestro catálogo:\n\n{$list}\n\n"
                . "Dime el nombre o el ID del que te guste, o elige del catálogo 👇"
            );
            $this->whatsappMessageService->sendCategoryList($client);
            $this->clientService->updateClientAndBroadcast($client, ['status' => 'INTERESADO']);
            return;
        }

        $this->whatsappMessageService->reply($client,
            "No tengo ese modelo exacto en stock ahora 😔 Pero mira lo que sí tenemos disponible:"
        );
        $this->whatsappMessageService->sendCategoryList($client);
        $this->clientService->updateClientAndBroadcast($client, ['status' => 'INTERESADO']);
    }

    protected function presentProductFromCatalog(Client $client, Product $product, ?string $intro = null): void
    {
        $this->whatsappMessageService->sendProductInfo($client, $product, $intro);
        $this->clientService->updateClientAndBroadcast($client, [
            'status' => 'INTERESADO',
            'priority' => 'ALTA',
            'state' => [
                'step' => ClientStateMachine::STEP_WAITING_CONFIRMATION,
                'product_id' => $product->id,
            ],
            'confirmation_requested_at' => now(),
            'followup_3_sent_at' => null,
            'followup_15_sent_at' => null,
            'followup_stopped_at' => null,
        ]);
        $this->whatsappMessageService->sendButtons($client, '¿Confirmas tu pedido?', ['Sí, lo quiero 💚', 'Ver otros']);
    }

    protected function canUseQuickIntents(string $step): bool
    {
        return in_array($step, [
            ClientStateMachine::STEP_START,
            ClientStateMachine::STEP_WAITING_CONFIRMATION,
        ], true);
    }

    /**
     * Responde con productos del inventario sin usar Gemini.
     */
    protected function tryInventorySearchReply(Client $client, string $text, array $state): bool
    {
        $products = $this->searchService->searchFromMessage($text, $state);

        if ($products->isEmpty()) {
            return false;
        }

        if ($products->count() === 1) {
            $this->presentProductFromCatalog($client, $products->first());
            return true;
        }

        $list = $this->searchService->formatProductListForChat($products->take(6));
        $this->whatsappMessageService->reply($client,
            "¡Claro hermosa! 💕 Esto tengo *con stock* ahora:\n\n{$list}\n\n"
            . "Dime cuál te interesa (nombre o ID) y te paso colores y tallas."
        );
        $this->clientService->updateClientAndBroadcast($client, ['status' => 'INTERESADO']);
        return true;
    }

    protected function replyWithInventoryCatalog(Client $client): void
    {
        $top = $this->searchService->getTopSelling(6);
        if ($top->isEmpty()) {
            $this->whatsappMessageService->reply($client,
                "Ahora mismo estamos actualizando inventario 📦 Escribe *asesor* y te ayuda una persona del equipo."
            );
            return;
        }

        $list = $this->searchService->formatProductListForChat($top);
        $this->whatsappMessageService->reply($client,
            "Te muestro lo que sí tenemos disponible en tienda:\n\n{$list}\n\n"
            . "¿Cuál te gustaría ver con fotos y tallas?"
        );
        $this->whatsappMessageService->sendCategoryList($client);
        $this->clientService->updateClientAndBroadcast($client, ['status' => 'INTERESADO']);
    }

    protected function startVariantSelection(Client $client, int $productId): void
    {
        $product = $this->searchService->findAvailable($productId);
        if (!$product) {
            $this->whatsappMessageService->reply($client, "Ese producto ya no tiene stock 😔 ¿Te muestro otras opciones?");
            $this->whatsappMessageService->sendCategoryList($client);
            ClientStateMachine::resetFlow($client);
            return;
        }

        $opts = $this->variantSelectionService->formatVariantsForCustomer($product);
        $this->whatsappMessageService->reply($client,
            "¡Perfecto, hermosa! ✨ ¿En qué color y talla lo quieres?\n\nDisponible: {$opts}\n\nEjemplo: *Negro M*"
        );
        ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_COLLECT_VARIANT, [
            'product_id' => $product->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ORDER HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Persists a payment receipt image and marks the most recent pending order
     * for the client as PAGO RECIBIDO (pending human verification).
     */
    protected function capturePaymentReceipt(Client $client, string $binary): void
    {
        try {
            $url = $this->paymentService->savePaymentReceipt($binary, $client->phone);
            $state = $client->state ?? ['step' => 'start'];
            $expectedAmount = $this->paymentService->getExpectedPaymentAmount($state);
            $base64 = $this->imageService->toBase64($binary);
            $detectedAmount = $this->paymentService->extractAmountFromReceipt($base64);

            if ($detectedAmount !== null && $detectedAmount + 0.01 < $expectedAmount) {
                $missing = $expectedAmount - $detectedAmount;
                $this->whatsappMessageService->reply($client,
                    "¡Ay, hermosa! Veo que te equivocaste en el monto del Yape 😥\n\n"
                    . "Me llegó por *S/ " . number_format($detectedAmount, 2) . "* pero el pedido está en *S/ " . number_format($expectedAmount, 2) . "*.\n\n"
                    . "Completa la diferencia de *S/ " . number_format($missing, 2) . "* al mismo número para poder dejarlo programado por ti, linda. 💕"
                );
                return;
            }

            if ($detectedAmount === null) {
                $this->whatsappMessageService->reply($client, "Recibí tu captura, hermosa. La voy a pasar a revisión para confirmar el monto y dejar tu pedido programado. 💕");
                $this->clientService->updateClientAndBroadcast($client, [
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
                ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_COLLECT_DELIVERY_DETAILS_MOTORIZADO, [
                    'payment_receipt_url' => $url,
                    'paid_amount' => $detectedAmount,
                ]);
                $this->clientService->updateClientAndBroadcast($client, [
                    'status' => 'PAGO RECIBIDO',
                    'priority' => 'ALTA',
                    'payment_receipt_url' => $url,
                    'paid_amount' => $detectedAmount,
                ]);
                $this->whatsappMessageService->reply($client,
                    "¡Pago recibido, hermosa! ✅\n\n"
                    . "Para programar tu envío por motorizado, envíame estos datos en un solo mensaje:\n\n"
                    . "Nombre completo, celular, dirección escrita y tu ubicación en tiempo real. 🛵"
                );
                return;
            }

            ClientStateMachine::transitionStep($client, ClientStateMachine::STEP_COLLECT_DELIVERY_DETAILS_SHALOM, [
                'payment_receipt_url' => $url,
                'paid_amount' => $detectedAmount,
            ]);
            $this->clientService->updateClientAndBroadcast($client, [
                'status' => 'PAGO RECIBIDO',
                'priority' => 'ALTA',
                'payment_receipt_url' => $url,
                'paid_amount' => $detectedAmount,
            ]);
            $this->whatsappMessageService->reply($client,
                "¡Pago recibido, hermosa! ✅\n\n"
                . "Para enviarlo por Shalom, envíame estos datos en un solo mensaje:\n\n"
                . "Nombre completo, DNI, celular y sede exacta de Shalom. 🚚"
            );
        } catch (\Throwable $e) {
            Log::error('capturePaymentReceipt failed: ' . $e->getMessage());
            $this->whatsappMessageService->reply($client, "Recibí tu comprobante pero hubo un problema técnico al guardarlo 😅. Un asesor lo revisará personalmente en breve.");
            $this->clientService->updateClientAndBroadcast($client, ['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
        }
    }

    protected function finalizePaidOrder(Client $client, array $state): void
    {
        if (empty(trim($state['delivery_details'] ?? ''))) {
            $this->whatsappMessageService->reply($client, "Hermosa, necesito los datos completos para programar tu pedido por favor 💕");
            return;
        }

        try {
            $order = $this->orderService->finalizeOrder($client, $state);

            if ($order) {
                $msg = $this->orderService->buildConfirmationMessage($order, $client);
                $this->whatsappMessageService->reply($client, $msg);
                ClientStateMachine::resetFlow($client);
            }
        } catch (\Exception $e) {
            if ($e->getMessage() === 'OUT_OF_STOCK') {
                $this->whatsappMessageService->reply($client,
                    "😔 Justo se acabó el stock de esa opción mientras conversabas. "
                    . "¿Quieres que te muestre alternativas muy parecidas?"
                );
                ClientStateMachine::resetFlow($client);
                return;
            }
            Log::error('finalizePaidOrder failed: ' . $e->getMessage());
            $this->whatsappMessageService->reply($client, "Tuve un problema al registrar tu pedido 🙅‍♀️. Un asesor te escribirá en unos minutos.");
        }
    }

}
