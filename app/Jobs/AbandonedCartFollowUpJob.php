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

class AbandonedCartFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(\App\Services\Messaging\WhatsAppMessageService $waMsg): void
    {
        $twoHoursAgo = now()->subHours(2);

        $clients = Client::whereIn('status', ['CONSULTANDO', 'INTERESADO'])
            ->where('last_interaction_at', '<', $twoHoursAgo)
            ->whereNull('opted_out_at')
            ->whereNotNull('phone')
            ->get();

        foreach ($clients as $client) {
            try {
                if ($client->opted_out_at !== null || ($client->followup_count ?? 0) >= 3) {
                    $this->logAutomation($client, 'blocked', null, 'opted_out_or_limit_reached');
                    continue;
                }

                $message = "Hermosa, ¿te separo la prenda que estabas viendo? 💕\n\n"
                    . "El stock se mueve rápido por TikTok Live, pero te ayudo a confirmarlo en un ratito.";

                if (!$client->canReceiveFreeformWhatsApp()) {
                    // Ventana de 24h cerrada, usar plantilla
                    $templateName = 'abandono_carrito_1';
                    $success = $waMsg->sendTemplateMessage($client, $templateName, 'es', [
                        [
                            'type' => 'body',
                            'parameters' => [['type' => 'text', 'text' => $client->name ?? 'hermosa']]
                        ]
                    ]);
                    
                    if (!$success) {
                        $this->logAutomation($client, 'failed', null, 'template_failed');
                        continue;
                    }
                } else {
                    // Ventana abierta, mensaje libre
                    $waMsg->reply($client, $message);
                }

                $client->update([
                    'last_interaction_at' => now(),
                    'last_followup_at' => now(),
                    'followup_count' => ($client->followup_count ?? 0) + 1,
                    'status' => 'INTERESADO',
                ]);
                $this->logAutomation($client, 'sent', $message);
                Log::info("Abandoned cart follow-up sent to {$client->phone}");
            } catch (\Exception $e) {
                Log::error("AbandonedCartFollowUpJob error for {$client->phone}: " . $e->getMessage());
            }
        }
    }

    protected function logAutomation(Client $client, string $status, ?string $message = null, ?string $reason = null): void
    {
        AutomationLog::create([
            'client_id' => $client->id,
            'type' => 'abandoned_cart_followup',
            'status' => $status,
            'message' => $message,
            'reason' => $reason,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
