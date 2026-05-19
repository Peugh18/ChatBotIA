<?php

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Client;
use App\Models\Message;
use App\Models\DeliveryZone;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    // Evitar que realmente se despachen jobs o eventos reales durante el test
    Queue::fake();
    Event::fake();
    
    // Limpiar base de datos (usando RefreshDatabase idealmente, asumiendo que el testcase lo tiene)
    Client::query()->delete();
    Message::query()->delete();
});

it('rejects webhooks without valid signature', function () {
    Config::set('services.whatsapp.app_secret', 'secret');

    $payload = json_encode(['entry' => []]);
    
    // Sin header
    $response = $this->postJson('/webhook', json_decode($payload, true));
    $response->assertStatus(403);
    
    // Con header incorrecto
    $response = $this->postJson('/webhook', json_decode($payload, true), [
        'X-Hub-Signature-256' => 'sha256=invalidhash'
    ]);
    $response->assertStatus(403);
    
    // Con header correcto
    $validHash = hash_hmac('sha256', $payload, 'secret');
    $response = $this->postJson('/webhook', json_decode($payload, true), [
        'X-Hub-Signature-256' => 'sha256=' . $validHash
    ]);
                     
    $response->assertStatus(200);
    Queue::assertPushed(ProcessWhatsAppWebhook::class);
});

it('prevents duplicate processing of the same message_id', function () {
    $messageId = 'wamid.HBgLMTU1NTIzMzAxMTEVAgASGBQzQ';
    $payload = buildWhatsAppPayload('Hola', $messageId, '51999888777', 'Test User');

    $job = new ProcessWhatsAppWebhook($payload);
    
    // Procesar la primera vez
    $job->handle(app(\App\Services\Utility\MessageHandler::class));
    
    $firstCount = Message::count();
    expect($firstCount)->toBeGreaterThanOrEqual(1);
    
    // Procesar la segunda vez
    $job->handle(app(\App\Services\Utility\MessageHandler::class));
    
    // Sigue habiendo la misma cantidad de mensajes (deduplicación)
    expect(Message::count())->toBe($firstCount);
});

it('responds to a local greeting without calling Gemini', function () {
    $payload = buildWhatsAppPayload('hola', 'msg_saludo_1', '51999888777', 'Test User');
    
    $job = new ProcessWhatsAppWebhook($payload);
    $job->handle(app(\App\Services\Utility\MessageHandler::class));

    $client = Client::where('phone', '51999888777')->first();
    expect($client)->not->toBeNull();
    expect($client->status)->toBe('INTERESADO');
    
    // Se debió haber guardado nuestro mensaje saliente (from_me = true)
    $botReply = Message::where('client_id', $client->id)->where('from_me', true)->first();
    expect($botReply)->not->toBeNull();
    expect($botReply->body)->toContain('Soy *Roma*');
});

it('handles variant -> shipping -> payment flow correctly', function () {
    $client = Client::create([
        'phone' => '51987654321',
        'name' => 'Flow User',
        'status' => 'INTERESADO',
        'state' => ['step' => 'collect_shipping', 'product_id' => 1, 'variant_id' => 1]
    ]);

    DeliveryZone::firstOrCreate(['district' => 'Miraflores'], ['motorizado_cost' => 10.0, 'shalom_cost' => 15.0]);

    // 1. Cliente elige Motorizado
    $payload = buildWhatsAppPayload('Motorizado', 'msg_flow_1', '51987654321');
    (new ProcessWhatsAppWebhook($payload))->handle(app(\App\Services\Utility\MessageHandler::class));

    $client->refresh();
    expect($client->state['step'])->toBe('collect_delivery_district');

    // 2. Cliente escribe su distrito (Miraflores)
    $payload = buildWhatsAppPayload('Miraflores', 'msg_flow_2', '51987654321');
    (new ProcessWhatsAppWebhook($payload))->handle(app(\App\Services\Utility\MessageHandler::class));

    $client->refresh();
    expect($client->state['step'])->toBe('collect_payment_method');
    expect($client->state['district'])->toBe('Miraflores');

    // 3. Cliente elige Yape
    $payload = buildWhatsAppPayload('Yape', 'msg_flow_3', '51987654321');
    (new ProcessWhatsAppWebhook($payload))->handle(app(\App\Services\Utility\MessageHandler::class));

    $client->refresh();
    expect($client->state['step'])->toBe('awaiting_payment');
    expect($client->status)->toBe('ESPERANDO PAGO');
});

// Helper para construir el payload JSON
function buildWhatsAppPayload(string $text, string $messageId, string $phone, string $name = 'Test') {
    return [
        'entry' => [
            [
                'changes' => [
                    [
                        'value' => [
                            'contacts' => [
                                ['profile' => ['name' => $name], 'wa_id' => $phone]
                            ],
                            'messages' => [
                                [
                                    'from' => $phone,
                                    'id' => $messageId,
                                    'type' => 'text',
                                    'text' => ['body' => $text]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];
}
