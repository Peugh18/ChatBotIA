<?php

namespace App\Jobs;

use App\Services\Utility\MessageHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $body;

    /**
     * Create a new job instance.
     */
    public function __construct(array $body)
    {
        $this->body = $body;
    }

    /**
     * Execute the job.
     */
    public function handle(MessageHandler $messageHandler): void
    {
        try {
            $entry = $this->body['entry'][0] ?? null;
            $changes = $entry['changes'][0] ?? null;
            $value = $changes['value'] ?? null;
            $messageData = $value['messages'][0] ?? null;
            $contactData = $value['contacts'][0] ?? [];

            if ($messageData) {
                $messageHandler->process($messageData, $contactData);
                return;
            }

            // Webhooks de estado (delivered/read) — no requieren respuesta del bot
            if (!empty($value['statuses'])) {
                Log::debug('WhatsApp status webhook received', ['statuses' => $value['statuses']]);
            }
        } catch (\Exception $e) {
            Log::error('ProcessWhatsAppWebhook Error: ' . $e->getMessage(), [
                'body' => $this->body,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
