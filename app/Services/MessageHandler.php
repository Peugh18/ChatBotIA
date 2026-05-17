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

            $client->update(['last_interaction_at' => now()]);

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
            $client->update([
                'state' => ['step' => 'start'],
                'status' => 'INTERESADO',
                'confirmation_requested_at' => null,
                'followup_3_sent_at' => null,
                'followup_15_sent_at' => null,
                'followup_stopped_at' => null,
            ]);
            return;
        }

        // Shortcut: client tapped "Lo quiero 💚" on a product card — skip AI, go straight to variant selection
        $productIdFromState = $state['product_id'] ?? null;
        if ($productIdFromState && $this->looksLikeBuyIntent($cleanText)) {
            $product = Product::with('variants')->find($productIdFromState);
            if ($product) {
                $this->sendProductCard($client, $product);
                $opts = $product->variants
                    ->where('stock', '>', 0)
                    ->map(fn($v) => "• {$v->color} / T:{$v->size} ({$v->stock} disponibles)")
                    ->join("\n");
                $this->reply($client, "¿En qué color y talla lo quieres?\n\n{$opts}\n\nEscríbeme por ejemplo: *Negro M*");
                $state['step'] = 'collect_variant';
                $client->update(['state' => $state]);
                return;
            }
        }

        // Simple data-collection steps (no AI needed — just store the value)
        $dataStep = $state['step'] ?? 'start';
        if ($dataStep === 'collect_address') {
            $state['address'] = $text;
            $state['step']    = 'collect_name';
            $client->update(['state' => $state]);
            $this->reply($client, "Perfecto 📦 ¿Cuál es el nombre completo de quien recibe el paquete?");
            return;
        }
        if ($dataStep === 'collect_name') {
            $state['recipient_name'] = $text;
            $state['step']           = 'collect_shipping';
            $client->update(['state' => $state]);
            $this->reply($client, "¿Qué método de envío prefieres?\n\n🏍️ *Motorizado* (Lima)\n📦 *Shalom* (Provincias)");
            $this->sendButtons($client, 'Elige tu método de envío:', ['Motorizado 🛵', 'Shalom 🚚']);
            return;
        }
        if ($dataStep === 'collect_shipping') {
            $state['shipping'] = $text;
            $this->completeOrder($client, $state);
            $client->update(['state' => ['step' => 'start']]);
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
                    $client->update(['status' => 'INTERESADO']);
                }
                return true;

            case 'escalate':
                $this->reply($client,
                    "¡Claro! 🙌 En unos minutos un asesor del equipo te escribe por aquí mismo. "
                    . "Mientras tanto, si quieres ir adelantando, dime qué prenda te interesa."
                );
                $client->update(['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
                Log::info("Client {$client->phone} requested human (intent detector).");
                return true;

            case 'business_hours':
                $this->reply($client,
                    "🕒 Nuestro horario de atención por WhatsApp:\n\n*" . IntentDetector::BUSINESS_HOURS . "*\n\n"
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
                        . "• *Motorizado*: S/ " . number_format($zone->motorizado_cost, 2) . " (" . IntentDetector::MOTORIZADO_WINDOW . ")\n"
                        . "• *Shalom Lima*: S/ " . number_format($zone->shalom_cost, 2) . "\n\n"
                        . "¿Qué método prefieres, hermosa? 💛"
                    );
                    return true;
                }
                return false;

            case 'delivery_info':
                $this->reply($client,
                    "📦 *Opciones de envío:*\n\n"
                    . "🏍️ *Motorizado* (Lima): tarifa según distrito — " . IntentDetector::MOTORIZADO_WINDOW . ".\n"
                    . "📦 *Shalom Lima*: S/ " . IntentDetector::SHALOM_LIMA . ".\n"
                    . "📦 *Shalom Provincia*: ~S/ " . IntentDetector::SHALOM_PROVINCIA . " en promedio.\n\n"
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
                $client->update(['status' => 'INTERESADO']);
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
    protected function buildPaymentInfoMessage(): string
    {
        $yape  = IntentDetector::YAPE_NUMBER;
        $owner = IntentDetector::YAPE_HOLDER;
        return "💸 *Métodos de pago*\n\n"
            . "🔹 *Yape* a este mismo número: *{$yape}* ({$owner}).\n"
            . "   Envíame la captura y confirmamos en el acto. ⚡\n\n"
            . "🔹 *Tarjeta / Link de pago*: si prefieres pagar con tarjeta, "
            . "envíame estos datos y te genero el link:\n"
            . "   • Nombre completo\n"
            . "   • Correo electrónico\n"
            . "   • Número de celular\n"
            . "   • Monto a pagar";
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
            if ($this->geminiService->lastErrorCode === 'quota') {
                $this->reply($client, "Tuve un pequeño problema de conexión con mi sistema de inteligencia 🤖 Dame unos minutos y vuelve a escribirme. Si es urgente, escribe *asesor* y te atiende una persona del equipo.");
                // Do NOT escalate to human on quota — it's temporary, not a bot failure.
            } else {
                $this->reply($client, "Tuve un problema técnico momentáneo 😅 ¿Puedes repetirme lo que necesitas?");
            }
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
        $keywords = ['lo quiero', 'lo llevo', 'compro', 'me lo llevo', 'lo compro', 'sí lo quiero', 'si lo quiero', 'sí lo llevo', 'si lo llevo', 'me lo quedo', 'lo quiero comprar', 'sí', 'si'];
        foreach ($keywords as $k) {
            if (str_contains($text, $k)) return true;
        }
        return false;
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

        $yape  = IntentDetector::YAPE_NUMBER;
        $owner = IntentDetector::YAPE_HOLDER;

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
• Español peruano, tono cercano pero profesional ("hola", "te paso", "sí ahorita").
• Mensajes CORTOS (máx 4–6 líneas). Una idea por mensaje.
• Usa emojis moderados (1–2 por mensaje, nunca más).
• Tutea siempre. Llama al cliente por su nombre si lo sabes.
• NUNCA inventes productos, precios, colores o tallas que no estén en el catálogo.
• Si no tienes algo, NO mientas: ofrece la alternativa más cercana del catálogo real.

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
5. *Si confirma* → entrega info de pago (método ya tipificado abajo) y luego pregunta método de envío.
6. *Si pide pagar con tarjeta* → solicita: Nombre completo, Correo, Celular, Monto.
7. *Coordinación de envío* (después de captura de pago):
   - Pregunta: "¿Te lo enviamos por motorizado o por Shalom?"
   - Si pregunta por costo → usa la tabla de tarifas reales abajo (NO inventes).
8. *Si elige motorizado* solicita ESTOS datos exactos:
   ✅ NOMBRE DEL VESTIDO Y COLOR
   ✅ NOMBRE COMPLETO
   ✅ CELULAR
   ✅ DIRECCIÓN ESCRITA
   ✅ UBICACIÓN EN TIEMPO REAL (pin de WhatsApp)
   Aclárale: "Las entregas son de L–S, 5–9 p.m. El motorizado se paga aparte al recibir."
9. *Si elige Shalom* solicita ESTOS datos exactos:
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
• Si ya eligió color y talla y aceptó comprar → action="collect_address".
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
                    $opts = $product->variants
                        ->where('stock', '>', 0)
                        ->map(fn($v) => "• {$v->color} / T:{$v->size} ({$v->stock} disponibles)")
                        ->join("\n");
                    $this->reply($client, "¿En qué color y talla lo quieres?\n\n{$opts}\n\nEscríbeme por ejemplo: *Negro M*");
                    $state['step']      = 'collect_variant';
                    $state['product_id'] = $product->id;
                    $updateData['state'] = $state;
                }
                break;

            case 'collect_address':
                $state['step']       = 'collect_address';
                if ($productId) $state['product_id'] = $productId;
                $updateData['state'] = $state;
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
            $client->update($updateData);
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
            $state['step']           = 'collect_address';
            $client->update(['state' => $state]);
            $this->reply($client, "✅ *{$variant->color} / T:{$variant->size}* confirmado.\n\nAhora dime tu dirección completa de envío 📦");
            return;
        }

        if ($variant) {
            $this->reply($client, "Lo siento, *{$variant->color} T:{$variant->size}* está agotado 😔 ¿Eliges otra opción?");
            return;
        }

        $opts = $product->variants->where('stock', '>', 0)
            ->map(fn($v) => "• {$v->color} / T:{$v->size}")->join("\n");
        $this->reply($client, "No entendí bien tu elección 😅 Las opciones disponibles son:\n\n{$opts}");
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
        if ($client->status === 'ESPERANDO PAGO') {
            $this->capturePaymentReceipt($client, $binary);
            return;
        }

        $client->update(['status' => 'CONSULTANDO', 'priority' => 'MEDIA']);

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
        $client->update(['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ORDER HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    protected function sendProductInfo(Client $client, Product $product, ?string $intro = null): void
    {
        $product->loadMissing('variants');

        $variants = $product->variants
            ->where('stock', '>', 0)
            ->map(fn($v) => "  • {$v->color} / T:{$v->size}: {$v->stock} en stock")
            ->join("\n");

        $prefix = $intro ? $intro . "\n\n" : '';
        $msg    = $prefix
            . "*{$product->name}*\n"
            . "💰 Precio: S/ {$product->price}\n"
            . "📝 {$product->description}\n\n"
            . "*Disponibilidad:*\n{$variants}\n\n"
            . "¿Te lo llevo? Responde *Sí* para iniciar tu pedido 🛍️";

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
                    'shipping_address' => $state['address']   ?? 'Por confirmar',
                    'shipping_method'  => $state['shipping']  ?? 'Por confirmar',
                    'status'           => 'PENDIENTE',
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
                $client->update(['state' => ['step' => 'start'], 'status' => 'CONSULTANDO']);
                return;
            }
            Log::error('completeOrder failed: ' . $e->getMessage());
            $this->reply($client, "Tuve un problema al registrar tu pedido 🙅‍♀️. Un asesor te escribirá en unos minutos.");
            $client->update(['state' => ['step' => 'start'], 'status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
            return;
        } catch (\Throwable $e) {
            Log::error('completeOrder unexpected: ' . $e->getMessage());
            $this->reply($client, "Tuve un problema al registrar tu pedido 🙅‍♀️. Un asesor te escribirá en unos minutos.");
            $client->update(['state' => ['step' => 'start'], 'status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
            return;
        }

        $recipientName  = $state['recipient_name'] ?? $client->name ?? 'Cliente';
        $variantDetails = " ({$variant->color}, T:{$variant->size})";
        $qtyDetails     = $quantity > 1 ? " × {$quantity}" : '';
        $orderNum       = str_pad($order->id, 5, '0', STR_PAD_LEFT);

        $this->reply($client,
            "🎉 *¡Pedido #{$orderNum} registrado, {$recipientName}!*\n\n"
            . "🔹 *Producto:* {$product->name}{$variantDetails}{$qtyDetails}\n"
            . "🔹 *Total:* S/ " . number_format($order->total, 2) . "\n"
            . "🔹 *Envío a:* " . ($state['address'] ?? 'Por confirmar') . "\n"
            . "🔹 *Método:* " . ($state['shipping'] ?? 'Por confirmar') . "\n\n"
            . "Por favor realiza el pago y envíame la captura de pantalla aquí 📸"
        );

        $this->sendButtons($client, '¿Cómo prefieres pagar?', ['Yape �', 'Tarjeta 💳']);

        $client->update(['status' => 'ESPERANDO PAGO', 'priority' => 'ALTA']);

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

            $order = Order::where('client_id', $client->id)
                ->whereIn('status', ['PENDIENTE', 'ESPERANDO PAGO'])
                ->latest()->first();

            if ($order) {
                $order->update([
                    'payment_receipt_url' => $url,
                    'status'              => 'PAGO RECIBIDO',
                ]);
            }

            $client->update(['status' => 'PAGO RECIBIDO', 'priority' => 'ALTA']);

            $orderNum = $order ? '#' . str_pad($order->id, 5, '0', STR_PAD_LEFT) : '';
            $this->reply($client,
                "¡Recibí tu comprobante! ✅ Voy a verificarlo en unos minutos y te confirmo el envío de tu pedido {$orderNum}. "
                . "Gracias por tu compra 💛"
            );
        } catch (\Throwable $e) {
            Log::error('capturePaymentReceipt failed: ' . $e->getMessage());
            $this->reply($client, "Recibí tu comprobante pero hubo un problema técnico al guardarlo 😅. Un asesor lo revisará personalmente en breve.");
            $client->update(['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
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

    // ─────────────────────────────────────────────────────────────────────────
    // UTILITIES
    // ─────────────────────────────────────────────────────────────────────────

    protected function reply(Client $client, string $body): void
    {
        $this->whatsAppService->sendMessage($client->phone, $body);

        Message::create([
            'client_id' => $client->id,
            'from_me'   => true,
            'body'      => $body,
            'type'      => 'text',
        ]);

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

        $variantSummary = $product->variants
            ->groupBy('color')
            ->map(fn($vs, $color) => $color . ' (' . $vs->pluck('size')->unique()->sort()->implode(', ') . ')')
            ->implode(' | ');

        $body = "*{$product->name}*\n"
              . "{$priceText}\n"
              . ($product->description ? mb_strimwidth($product->description, 0, 80, '…') . "\n" : '')
              . "Colores/Tallas: {$variantSummary}";

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
