<?php

namespace App\Services\Integration;

use App\Events\ClientStatusUpdated;
use App\Events\MessageReceived;
use App\Models\Client;
use App\Models\Message;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RomaApiService
{
    public function isEnabled(): bool
    {
        return (bool) config('services.roma_api.enabled')
            && filled(config('services.roma_api.url'));
    }

    protected function messagesUrl(): string
    {
        return rtrim(config('services.roma_api.url'), '/') . '/api/messages';
    }

    /**
     * GET /api/messages — importa latest_messages de Supabase al CRM.
     *
     * @return array{imported: int, skipped: int, total: int, error?: string}
     */
    public function pullLatestMessages(int $limit = 50, ?string $phone = null): array
    {
        if (! $this->isEnabled()) {
            return ['imported' => 0, 'skipped' => 0, 'total' => 0, 'error' => 'roma-api disabled'];
        }

        try {
            $query = ['limit' => $limit];
            if ($phone) {
                $query['phone'] = preg_replace('/\D+/', '', $phone) ?: $phone;
            }

            $response = Http::timeout(15)
                ->withHeaders($this->requestHeaders())
                ->get($this->messagesUrl(), $query);

            if (! $response->successful()) {
                return [
                    'imported' => 0,
                    'skipped' => 0,
                    'total' => 0,
                    'error' => 'HTTP ' . $response->status(),
                ];
            }

            $data = $response->json();
            if (($data['status'] ?? '') !== 'API is running') {
                return [
                    'imported' => 0,
                    'skipped' => 0,
                    'total' => 0,
                    'error' => 'Unexpected API response',
                ];
            }

            $logs = $data['latest_messages'] ?? [];
            // Supabase devuelve los más recientes primero; importar en orden cronológico
            $logs = array_reverse($logs);

            $imported = 0;
            $skipped = 0;

            foreach ($logs as $log) {
                $result = $this->importLog($log);
                if ($result === 'imported') {
                    $imported++;
                } else {
                    $skipped++;
                }
            }

            return [
                'imported' => $imported,
                'skipped' => $skipped,
                'total' => count($logs),
            ];
        } catch (\Throwable $e) {
            Log::warning('roma-api pull error: ' . $e->getMessage());

            return [
                'imported' => 0,
                'skipped' => 0,
                'total' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $log
     * @return 'imported'|'skipped'|'invalid'
     */
    public function importLog(array $log): string
    {
        $waId = $log['wa_id'] ?? null;
        $phone = $log['sender_phone'] ?? null;
        $body = $log['message_body'] ?? null;
        $direction = $log['direction'] ?? null;

        if (! $waId || ! $phone || ! $body || ! in_array($direction, ['inbound', 'outbound'], true)) {
            return 'invalid';
        }

        if (Message::where('meta_message_id', $waId)->exists()) {
            return 'skipped';
        }

        $phone = preg_replace('/\D+/', '', (string) $phone) ?: (string) $phone;
        $fromMe = $direction === 'outbound';

        // Solo evitar duplicar salientes (el CRM ya los guardó); los entrantes siempre por wa_id
        if ($fromMe && $this->isDuplicateOutbound($phone, $body)) {
            return 'skipped';
        }

        $client = Client::firstOrCreate(
            ['phone' => $phone],
            ['name' => $phone, 'status' => 'NUEVO', 'priority' => 'BAJA']
        );

        $timestamp = isset($log['timestamp']) ? strtotime($log['timestamp']) : false;

        $msg = Message::withoutEvents(function () use ($client, $fromMe, $body, $waId, $timestamp) {
            $attrs = [
                'client_id' => $client->id,
                'from_me' => $fromMe,
                'body' => $body,
                'type' => 'text',
                'meta_message_id' => $waId,
            ];

            if ($timestamp) {
                $attrs['created_at'] = date('Y-m-d H:i:s', $timestamp);
                $attrs['updated_at'] = $attrs['created_at'];
            }

            return Message::create($attrs);
        });

        $client->update(['last_interaction_at' => $msg->created_at ?? now()]);
        if (! $fromMe) {
            $client->update(['last_customer_message_at' => $msg->created_at ?? now()]);
        }

        SafeBroadcast::event(new MessageReceived($msg));
        SafeBroadcast::event(new ClientStatusUpdated($client->fresh()));

        return 'imported';
    }

    /**
     * POST message to roma-api /api/messages (Laravel → Supabase).
     */
    public function syncMessage(Message $message): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $message->loadMissing('client');

        $client = $message->client;
        if (! $client?->phone) {
            return;
        }

        $body = $message->body;
        if (! filled($body)) {
            $body = match ($message->type) {
                'image' => '[Imagen]',
                'audio' => '[Audio]',
                'video' => '[Video]',
                'document' => '[Documento]',
                'button' => '[Botones]',
                default => '[Mensaje]',
            };
        }

        $payload = [
            'wa_id' => $message->meta_message_id ?? ('laravel-' . $message->id),
            'sender_phone' => $client->phone,
            'message_body' => $body,
            'direction' => $message->from_me ? 'outbound' : 'inbound',
        ];

        try {
            $response = Http::timeout(10)
                ->withHeaders($this->requestHeaders())
                ->post($this->messagesUrl(), $payload);

            if (! $response->successful()) {
                Log::warning('roma-api sync failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'payload' => $payload,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('roma-api sync error: ' . $e->getMessage(), ['payload' => $payload]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function isDuplicateOutbound(string $phone, string $body): bool
    {
        $client = Client::where('phone', $phone)->first();
        if (! $client) {
            return false;
        }

        return Message::query()
            ->where('client_id', $client->id)
            ->where('from_me', true)
            ->where('body', $body)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();
    }

    protected function requestHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'ngrok-skip-browser-warning' => 'true',
            'X-Roma-Source' => 'laravel',
        ];

        $token = config('services.roma_api.token');
        if ($token) {
            $headers['X-Roma-Sync-Token'] = $token;
        }

        return $headers;
    }
}
