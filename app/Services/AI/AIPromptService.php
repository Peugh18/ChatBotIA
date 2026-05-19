<?php

namespace App\Services\AI;

use App\Models\Client;
use App\Models\Message;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\HumanRequestedNotification;
use App\Services\Business\ClientService;
use App\Services\Business\DeliveryService;
use App\Services\Business\ProductSearchService;
use App\Services\State\ClientStateMachine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class AIPromptService
{
    public function __construct(
        protected IntentService $intent,
        protected ProductSearchService $searchService,
        protected DeliveryService $deliveryService,
        protected ClientService $clientService,
    ) {}

    public function buildSystemPrompt(Client $client, array $state, ?string $userText = null): string
    {
        $storeName = Setting::get('store_name', 'Roma Store');
        $signature = Setting::get('store_signature', 'Roma');
        $businessHours = Setting::get('business_hours', IntentService::getBusinessHours());
        $currentStep = $state['step'] ?? ClientStateMachine::STEP_START;
        $productId = $state['product_id'] ?? null;

        $relevantProducts = $userText
            ? $this->searchService->searchFromMessage($userText, $state)
            : collect();

        if ($relevantProducts->isEmpty()) {
            $relevantProducts = $this->searchService->search([]);
        }

        $catalogBlock = $this->searchService->formatForPrompt($relevantProducts->take(6));
        $zonesBlock = $this->deliveryService->formatZonesForPrompt();
        $shalomLima = IntentService::getShalomLima();
        $shalomProv = IntentService::getShalomProvincia();

        $prompt = "Eres {$signature}, vendedora de {$storeName} por WhatsApp.\n"
            . "Horario: {$businessHours}.\n\n"
            . "=== REGLAS ABSOLUTAS (INVENTARIO) ===\n"
            . "1. SOLO puedes ofrecer productos listados en CATÁLOGO DISPONIBLE abajo. NUNCA inventes nombres, precios, colores, tallas ni IDs.\n"
            . "2. Si el cliente pide algo que NO está en el catálogo, dilo con amabilidad y ofrece alternativas del catálogo o action show_categories.\n"
            . "3. product_id en tu JSON DEBE ser uno de los [ID:...] del catálogo. Si no hay match, no uses show_product.\n"
            . "4. NO des número de Yape ni pidas método de pago hasta que el cliente confirme el pedido (action ask_confirmation).\n"
            . "5. Costos de envío: usa SOLO la tabla de distritos o estos valores fijos — Shalom Lima S/ {$shalomLima}, provincia ~S/ {$shalomProv}. Motorizado: cotiza SOLO con el precio del distrito en la tabla.\n"
            . "6. Si no puedes responder con certeza: message exacto \"Voy a realizar la consulta a un agente especializado y en breve le brindamos una respuesta.\" y action escalate.\n\n";

        if ($currentStep === ClientStateMachine::STEP_COLLECT_VARIANT) {
            $prompt .= "El cliente elige color/talla. Guíalo con las variantes del producto actual.\n\n";
        } elseif ($currentStep === ClientStateMachine::STEP_WAITING_CONFIRMATION) {
            $prompt .= "Esperas que confirme si desea el pedido. Usa ask_confirmation si aún no confirmó.\n\n";
        }

        if ($productId) {
            $product = $this->searchService->findAvailable((int) $productId);
            if ($product) {
                $prompt .= "PRODUCTO EN CONVERSACIÓN:\n"
                    . $this->searchService->formatForPrompt(collect([$product])) . "\n\n";
            }
        }

        $prompt .= "CATÁLOGO DISPONIBLE (con stock real):\n{$catalogBlock}\n\n"
            . "DISTRITOS LIMA — DELIVERY MOTORIZADO / SHALOM (precios oficiales):\n{$zonesBlock}\n\n"
            . "Cliente: " . ($client->name ?: 'sin nombre') . " | CRM status: {$client->status}\n";

        $tags = Tag::orderBy('name')->pluck('name')->all();
        if ($tags !== []) {
            $prompt .= 'Tags CRM (solo estos): ' . implode(', ', $tags) . "\n";
        }

        $prompt .= "\nGUIÓN:\n"
            . "- Catálogo: action show_categories o muestra productos del listado.\n"
            . "- Interés en un producto: describe precio/colores del catálogo y action ask_confirmation.\n"
            . "- Confirmación: frase \"nos confirmas si deseas realizar el pedido para poder ayudarte hermosa\" + ask_confirmation.\n\n"
            . "Responde SOLO JSON válido:\n"
            . "{\n"
            . "  \"message\": \"texto al cliente\",\n"
            . "  \"action\": null | \"show_product\" | \"show_categories\" | \"show_photo\" | \"ask_confirmation\" | \"escalate\",\n"
            . "  \"product_id\": null | number,\n"
            . "  \"tags_to_add\": [],\n"
            . "  \"tags_to_remove\": [],\n"
            . "  \"note\": null | \"nota interna\"\n"
            . "}\n";

        return $prompt;
    }

    public function buildConversationHistory(Client $client): array
    {
        $recentMessages = Message::where('client_id', $client->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        $history = [];
        foreach ($recentMessages as $msg) {
            if (empty($msg->body)) {
                continue;
            }
            $role = $msg->from_me ? 'model' : 'user';
            $history[] = ['role' => $role, 'parts' => [['text' => $msg->body]]];
        }

        return $history;
    }

    public function cleanJsonResponse(?string $text): string
    {
        if (!$text) {
            return '{}';
        }

        $text = preg_replace('/```json\s*/', '', $text);
        $text = preg_replace('/```\s*$/', '', $text);
        $text = str_replace(['```', '```json'], '', $text);

        return trim($text);
    }

    /**
     * Run post-AI actions; returns whether confirmation timers should start.
     */
    public function executeAIAction(Client $client, array $parsed, array $state, callable $callback): bool
    {
        $this->clientService->syncAiTags(
            $client,
            $parsed['tags_to_add'] ?? [],
            $parsed['tags_to_remove'] ?? []
        );

        if (!empty($parsed['note'])) {
            $this->clientService->writeAiNote($client, $parsed['note']);
        }

        $action = $parsed['action'] ?? null;
        $productId = isset($parsed['product_id']) ? (int) $parsed['product_id'] : null;
        $armConfirmation = false;

        if ($action === 'escalate') {
            $this->escalateToHuman($client);
            return false;
        }

        $product = $productId ? $this->searchService->findAvailable($productId) : null;

        if ($action === 'show_product') {
            if ($product) {
                $callback('sendProductCard', [$client, $product]);
                $this->setProductInState($client, $product->id, ClientStateMachine::STEP_WAITING_CONFIRMATION);
                $armConfirmation = true;
            } else {
                Log::warning("AI requested invalid product_id {$productId} for client {$client->id}");
            }
        }

        if ($action === 'show_photo' && $product) {
            $callback('sendProductPhoto', [$client, $product->id]);
            $this->setProductInState($client, $product->id, $state['step'] ?? ClientStateMachine::STEP_START);
        }

        if ($action === 'show_categories') {
            $callback('sendCategoryList', [$client]);
        }

        if ($action === 'ask_confirmation') {
            if ($product) {
                $this->setProductInState($client, $product->id, ClientStateMachine::STEP_WAITING_CONFIRMATION);
            }
            $callback('sendButtons', [$client, '¿Confirmas tu pedido?', ['Sí, lo quiero 💚', 'Ver otros']]);
            $armConfirmation = true;
        }

        return $armConfirmation;
    }

    protected function setProductInState(Client $client, int $productId, string $step): void
    {
        $state = $client->state ?? [];
        $state['product_id'] = $productId;
        $state['step'] = $step;

        $this->clientService->updateClientAndBroadcast($client, [
            'state' => $state,
            'status' => 'INTERESADO',
            'priority' => 'ALTA',
        ]);
    }

    protected function escalateToHuman(Client $client): void
    {
        $this->clientService->updateClientAndBroadcast($client, [
            'status' => 'NECESITA ASESOR',
            'priority' => 'ALTA',
        ]);

        Notification::send(User::all(), new HumanRequestedNotification($client));
        Log::info("Client {$client->phone} escalated via AI action.");
    }
}
