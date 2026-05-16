<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Message;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;

class MessageHandler
{
    protected $whatsAppService;
    protected $geminiService;
    protected $imageService;

    public function __construct(
        WhatsAppService $whatsAppService, 
        GeminiService $geminiService,
        ImageService $imageService
    ) {
        $this->whatsAppService = $whatsAppService;
        $this->geminiService = $geminiService;
        $this->imageService = $imageService;
    }

    public function process($messageData, $contactData)
    {
        $phone = $messageData['from'];
        $name = $contactData['profile']['name'] ?? null;
        $messageId = $messageData['id'];

        // 1. WhatsApp Deduplication: check if this message ID has already been registered with concurrent lock
        $lockKey = "whatsapp_msg_lock_{$messageId}";
        $lock = cache()->lock($lockKey, 15); // 15s lock
        if (!$lock->get()) {
            Log::info("Mensaje en proceso concurrente evitado (Atomic Lock): {$messageId}");
            return;
        }

        try {
            $existingMessage = Message::where('meta_message_id', $messageId)->first();
            if ($existingMessage) {
                Log::info("Mensaje de WhatsApp ya procesado (Deduplicado): {$messageId}");
                $lock->release();
                return;
            }

            // 2. Get or Create Client
            $client = Client::firstOrCreate(
                ['phone' => $phone],
                ['name' => $name, 'status' => 'NUEVO', 'priority' => 'BAJA']
            );

            // Update name if we didn't have it and now have it
            if ($name && empty($client->name)) {
                $client->update(['name' => $name]);
            }

            // 3. Register Incoming Message
            $type = $messageData['type'];
            $body = ($type === 'text') ? $messageData['text']['body'] : null;
            $mediaId = ($type === 'image') ? $messageData['image']['id'] : null;

            $message = Message::create([
                'client_id' => $client->id,
                'from_me' => false,
                'body' => $body,
                'type' => $type,
                'meta_message_id' => $messageId
            ]);

            // 4. Update last interaction
            $client->update(['last_interaction_at' => now()]);

            // 5. Handle Flow
            if ($type === 'image') {
                $this->handleImageMessage($client, $mediaId);
            } elseif ($type === 'text') {
                $this->handleTextMessage($client, $body);
            } elseif ($type === 'interactive') {
                $option = $messageData['interactive']['button_reply']['title'] ?? '';
                $this->handleTextMessage($client, $option);
            }

            // 6. Mark as Read
            $this->whatsAppService->markAsRead($messageId);
        } finally {
            $lock->release();
        }
    }

    protected function handleImageMessage(Client $client, $mediaId)
    {
        $client->update(['status' => 'CONSULTANDO', 'priority' => 'MEDIA']);

        $imageBinary = $this->imageService->downloadWhatsAppImage($mediaId);
        if (!$imageBinary) {
            $this->reply($client, "Lo siento, no pude procesar tu imagen. ¿Podrías intentarlo de nuevo?");
            return;
        }

        $base64 = $this->imageService->toBase64($imageBinary);
        
        // Fetch product names for context
        $catalogContext = Product::all(['name', 'sku', 'price'])->toJson();

        $prompt = "Actúa como un vendedor experto de una tienda de ropa. 
        Analiza esta imagen de una captura de pantalla de un TikTok Live. 
        Identifica qué prenda es y compárala con nuestro catálogo: {$catalogContext}.
        Si encuentras una coincidencia cercana, responde con el nombre exacto del producto y su SKU en este formato JSON:
        { \"match\": true, \"sku\": \"SKU-AQUI\", \"product_name\": \"NOMBRE\", \"confidence\": 0.9 }
        Si no hay coincidencia, responde: { \"match\": false }";

        $aiResponse = $this->geminiService->analyzeImage($base64, $prompt);
        $result = json_decode($this->cleanJsonResponse($aiResponse), true);

        if ($result && isset($result['match']) && $result['match']) {
            $product = Product::where('sku', $result['sku'])->first();
            if ($product) {
                $this->sendProductInfo($client, $product);
                return;
            }
        }

        $this->reply($client, "¡Qué linda prenda! No la encuentro exactamente en mi catálogo actual, pero déjame consultar con un asesor. ¿Te interesa algún otro modelo?");
        $client->update(['status' => 'NECESITA ASESOR', 'priority' => 'ALTA']);
    }

    protected function handleTextMessage(Client $client, $text)
    {
        $state = $client->state ?? ['step' => 'start'];
        $cleanText = strtolower(trim($text));

        // Cancellation keyword support
        if ($cleanText === 'cancelar' || $cleanText === 'salir') {
            $this->reply($client, "Entendido. He cancelado el proceso actual. ¿En qué más te puedo ayudar?");
            $client->update([
                'state' => ['step' => 'start'],
                'status' => 'INTERESADO'
            ]);
            return;
        }

        // Bypassing AI intent classifier if the client is in the middle of a purchase step
        if (isset($state['step']) && $state['step'] !== 'start') {
            $this->handleStepFlow($client, $text, $state);
            return;
        }

        // Direct Greetings Override (0ms latency, saves tokens, avoids stuck loops)
        $greetings = ['hola', 'buenas', 'buenos dias', 'buenas tardes', 'buenas noches', 'hi', 'hello', 'holaa', 'holaaa'];
        if (in_array($cleanText, $greetings)) {
            $intent = 'greeting';
            $intentData = ['intent' => 'greeting'];
        } else {
            // IA for Intent Detection
            $prompt = "Eres un asistente de ventas de ropa por WhatsApp. 
            Estado actual del cliente: {$client->status}.
            Mensaje del cliente: \"{$text}\".
            Determina la intención y responde en JSON:
            { \"intent\": \"greeting|purchase|info|payment_sent|other\", \"entities\": { \"product\": \"nombre_de_prenda_si_se_menciono_o_null\" } }";

            $aiResponse = $this->geminiService->chat($prompt);
            $intentData = json_decode($this->cleanJsonResponse($aiResponse), true);
            $intent = $intentData['intent'] ?? 'other';
        }

        switch ($intent) {
            case 'greeting':
                $this->reply($client, "¡Hola! Bienvenido a Roma Store. 🛍️\n\n¿Viste algo que te gustó en nuestro Live? Envíame una captura de pantalla o el nombre de la prenda para ayudarte con el pedido.");
                $client->update([
                    'status' => 'INTERESADO',
                    'state' => ['step' => 'start']
                ]);
                break;

            case 'purchase':
                // Check if product was mentioned in the message
                $productMentioned = $intentData['entities']['product'] ?? null;
                if ($productMentioned) {
                    $foundProduct = Product::where('name', 'like', "%{$productMentioned}%")->first();
                    if ($foundProduct) {
                        $this->sendProductInfo($client, $foundProduct);
                        break;
                    }
                }

                if (isset($state['product_id'])) {
                    $product = Product::with('variants')->find($state['product_id']);
                    if ($product) {
                        $options = $product->variants->map(fn($v) => "- {$v->color} / Talla {$v->size} ({$v->stock} disp.)")->implode("\n");
                        $this->reply($client, "¡Excelente elección! ¿En qué color y talla deseas tu prenda?\n\nOpciones disponibles:\n{$options}\n\nEscríbeme por ejemplo: 'Negro M'");
                        $state['step'] = 'waiting_variant';
                        $client->update(['state' => $state, 'status' => 'CONSULTANDO']);
                    } else {
                        $this->reply($client, "¡Excelente elección! Por favor, indícame tu dirección completa para el envío.");
                        $state['step'] = 'waiting_address';
                        $client->update(['state' => $state, 'status' => 'CONSULTANDO']);
                    }
                } else {
                    $this->reply($client, "¡Claro! ¿Qué prenda te gustaría comprar? Envíame una foto o el nombre.");
                }
                break;

            case 'payment_sent':
                $this->reply($client, "¡Gracias por el comprobante! 🙌 Lo estamos validando. En unos minutos te confirmaremos el pedido. 🔴");
                $client->update(['status' => 'PAGO RECIBIDO', 'priority' => 'ALTA']);
                break;

            case 'info':
                $productMentioned = $intentData['entities']['product'] ?? null;
                if ($productMentioned) {
                    $foundProduct = Product::where('name', 'like', "%{$productMentioned}%")->first();
                    if ($foundProduct) {
                        $this->sendProductInfo($client, $foundProduct);
                        break;
                    }
                }
                $this->reply($client, "¿Qué detalle te gustaría saber? (Tallas, colores o materiales).");
                break;

            default:
                if ($state['step'] === 'start') {
                    $this->reply($client, "¡Hola! No estoy seguro de cómo ayudarte con eso, pero si me envías una captura de pantalla de la prenda que te gustó, podré procesar tu pedido de inmediato. 😊");
                } else {
                    $this->handleStepFlow($client, $text, $state);
                }
                break;
        }
    }

    protected function handleStepFlow(Client $client, $text, $state)
    {
        $cleanText = strtolower(trim($text));

        switch ($state['step'] ?? 'start') {
            case 'waiting_confirmation':
                if (str_contains($cleanText, 'si') || str_contains($cleanText, 'sí')) {
                    $product = Product::with('variants')->find($state['product_id']);
                    if ($product) {
                        $options = $product->variants->map(fn($v) => "- {$v->color} / Talla {$v->size} ({$v->stock} disp.)")->implode("\n");
                        $this->reply($client, "¡Excelente elección! ¿En qué color y talla deseas tu prenda?\n\nOpciones disponibles:\n{$options}\n\nEscríbeme por ejemplo: 'Negro M'");
                        $state['step'] = 'waiting_variant';
                    } else {
                        $this->reply($client, "¡Excelente elección! Por favor, indícame tu dirección completa para el envío.");
                        $state['step'] = 'waiting_address';
                    }
                } else {
                    $this->reply($client, "No te preocupes. ¿Hay alguna otra prenda que te interese?");
                    $state = ['step' => 'start'];
                }
                break;

            case 'waiting_variant':
                $product = Product::with('variants')->find($state['product_id']);
                if (!$product) {
                    $this->reply($client, "Hubo un problema al buscar el producto. ¿Qué prenda deseas comprar?");
                    $state = ['step' => 'start'];
                    break;
                }

                $variantsContext = $product->variants->map(fn($v) => [
                    'id' => $v->id,
                    'color' => $v->color,
                    'size' => $v->size,
                    'stock' => $v->stock
                ])->toJson();

                $prompt = "El cliente escribió: \"{$text}\".
                Basado en las variantes de este producto: {$variantsContext}.
                Determina cuál es el ID de la variante (variant_id) que más se acerca a la elección del cliente.
                Responde ÚNICAMENTE en este formato JSON:
                { \"match\": true, \"variant_id\": \"ID_NUMERICO_AQUI\" }
                Si no hay coincidencia exacta o cercana, responde:
                { \"match\": false }";

                try {
                    $aiResponse = $this->geminiService->chat($prompt);
                    $result = json_decode($this->cleanJsonResponse($aiResponse), true);
                } catch (\Exception $e) {
                    $result = null;
                }

                if ($result && isset($result['match']) && $result['match']) {
                    $variant = ProductVariant::find($result['variant_id']);
                    if ($variant && $variant->stock > 0) {
                        $state['variant_id'] = $variant->id;
                        $state['selected_color'] = $variant->color;
                        $state['selected_size'] = $variant->size;
                        $this->reply($client, "Confirmado: Color *{$variant->color}*, Talla *{$variant->size}*.\n\nAhora, indícame tu dirección completa para el envío.");
                        $state['step'] = 'waiting_address';
                    } else if ($variant) {
                        $this->reply($client, "Lo siento, el color *{$variant->color}* en talla *{$variant->size}* está agotado temporalmente. Por favor elige otro color o talla.");
                    } else {
                        $this->reply($client, "No logré encontrar esa variante en nuestro stock. ¿Deseas probar con otro color o talla?");
                    }
                } else {
                    $options = $product->variants->map(fn($v) => "- {$v->color} / Talla {$v->size} ({$v->stock} disp.)")->implode("\n");
                    $this->reply($client, "No logré comprender tu elección. Por favor indícame uno de los colores y tallas disponibles:\n\n{$options}");
                }
                break;

            case 'waiting_address':
                $state['address'] = $text;
                $this->reply($client, "Perfecto. ¿Cuál es tu nombre completo para el paquete?");
                $state['step'] = 'waiting_name';
                break;

            case 'waiting_name':
                $state['name'] = $text;
                $this->reply($client, "¿Qué método de envío prefieres? (Motorizado en Lima / Shalom para provincias)");
                $state['step'] = 'waiting_shipping';
                break;

            case 'waiting_shipping':
                $state['shipping'] = $text;
                $this->completeOrder($client, $state);
                $state = ['step' => 'start']; // Reset the flow state so they can make new purchases
                break;
        }

        $client->update(['state' => $state]);
    }

    protected function sendProductInfo(Client $client, Product $product)
    {
        $variants = $product->variants->map(fn($v) => "- {$v->color} (Talla {$v->size}): {$v->stock} en stock")->implode("\n");
        
        $message = "*{$product->name}*\n";
        $message .= "💰 *Precio:* S/ {$product->price}\n";
        $message .= "📝 *Descripción:* {$product->description}\n\n";
        $message .= "*Disponibilidad:*\n{$variants}\n\n";
        $message .= "¿Deseas realizar el pedido? (Responde Sí/No)";

        $this->reply($client, $message);
        $client->update([
            'status' => 'INTERESADO',
            'priority' => 'ALTA',
            'state' => ['step' => 'waiting_confirmation', 'product_id' => $product->id]
        ]);
    }

    protected function completeOrder(Client $client, $state)
    {
        $product = Product::find($state['product_id']);
        
        // Save the order with the selected shipping method
        $order = Order::create([
            'client_id' => $client->id,
            'total' => $product->price,
            'shipping_address' => $state['address'],
            'shipping_method' => $state['shipping'] ?? null,
            'status' => 'PENDIENTE'
        ]);

        // Create the associated OrderItem for complete database integrity using selected variant
        $variantId = $state['variant_id'] ?? null;
        $variant = $variantId ? ProductVariant::find($variantId) : $product->variants()->first();
        
        if ($variant) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_variant_id' => $variant->id,
                'quantity' => 1,
                'price' => $product->price
            ]);

            // Deduct stock ("QUE SI HAY VENTA CUMPLIDA VAYA BAJANDO PORFA")
            if ($variant->stock >= 1) {
                $variant->decrement('stock', 1);
            }
        }

        $variantDetails = $variant ? " (Color: {$variant->color}, Talla: {$variant->size})" : "";

        $this->reply($client, "¡Listo {$state['name']}! Tu pedido #{$order->id} ha sido registrado.\n\n" .
            "🔹 *Detalle:* {$product->name}{$variantDetails}\n" .
            "🔹 *Monto:* S/ " . number_format($order->total, 2) . "\n\n" .
            "Por favor realiza el pago por Yape al número *959166911* (José Urcia) y envíame la captura aquí.");
        
        $client->update(['status' => 'ESPERANDO PAGO', 'priority' => 'ALTA']);
    }

    protected function reply(Client $client, $body)
    {
        $this->whatsAppService->sendMessage($client->phone, $body);
        
        Message::create([
            'client_id' => $client->id,
            'from_me' => true,
            'body' => $body,
            'type' => 'text'
        ]);
    }

    protected function cleanJsonResponse($text)
    {
        if (empty($text)) {
            return '{}';
        }
        // Remove markdown formatting like ```json ... ``` or ``` ... ```
        $text = preg_replace('/^```(?:json)?\s+/i', '', trim($text));
        $text = preg_replace('/\s+```$/', '', $text);
        return $text;
    }
}
