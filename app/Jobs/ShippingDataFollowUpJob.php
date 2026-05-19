<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\AutomationLog;
use App\Models\Message;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ShippingDataFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WhatsAppService $wa): void
    {
        $fifteenMinsAgo = now()->subMinutes(15);
        $clients = Client::where('status', 'ESPERANDO PAGO') // or specific status if applicable
            ->whereNotNull('phone')
            ->get();

        // In this implementation, we will look at the `state->step` and `updated_at`.
        // The user specifies that the client is asked for shipping details and hasn't responded.
        // If the state is collect_delivery_details_motorizado or collect_delivery_details_shalom
        // and 15 mins passed since last_interaction_at

        $clients = Client::whereNotNull('phone')
            ->where('last_interaction_at', '<=', $fifteenMinsAgo)
            ->where(function($query) {
                $query->where('state', 'like', '%"step":"collect_delivery_details_motorizado"%')
                      ->orWhere('state', 'like', '%"step":"collect_delivery_details_shalom"%');
            })
            // Solo mandamos el recordatorio 1 vez por este estado
            ->where(function($q) use ($fifteenMinsAgo) {
                $q->whereNull('last_followup_at')
                  ->orWhere('last_followup_at', '<', $fifteenMinsAgo);
            })
            ->get();

        foreach ($clients as $client) {
            try {
                // Prevenir spam
                if (!$client->canReceiveAutomation()) {
                    continue;
                }

                $body = "Hermosa por favor sus datos para poder programar el envío.";
                $this->send($wa, $client, $body);

                $client->update([
                    'last_followup_at' => now(),
                    'followup_count' => ($client->followup_count ?? 0) + 1,
                ]);

                $this->logAutomation($client, 'sent', $body);
                Log::info("15-minute shipping details follow-up sent to {$client->phone}");
            } catch (\Throwable $e) {
                Log::error("ShippingDataFollowUpJob error for {$client->phone}: " . $e->getMessage());
            }
        }
    }

    protected function send(WhatsAppService $wa, Client $client, string $body): void
    {
        $wa->sendMessage($client->phone, $body);

        Message::create([
            'client_id' => $client->id,
            'from_me' => true,
            'body' => $body,
            'type' => 'text',
        ]);
    }

    protected function logAutomation(Client $client, string $status, ?string $message = null, ?string $reason = null, array $context = []): void
    {
        AutomationLog::create([
            'client_id' => $client->id,
            'type' => 'shipping_data_followup',
            'status' => $status,
            'message' => $message,
            'reason' => $reason,
            'context' => $context,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
