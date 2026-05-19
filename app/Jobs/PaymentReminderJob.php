<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\AutomationLog;
use App\Models\Message;
use App\Services\AI\IntentService;
use App\Services\Messaging\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PaymentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(\App\Services\Messaging\WhatsAppMessageService $waMsg): void
    {
        $oneHourAgo = now()->subHour();

        $clients = Client::where('status', 'ESPERANDO PAGO')
            ->where('last_interaction_at', '<', $oneHourAgo)
            ->whereNull('opted_out_at')
            ->whereNotNull('phone')
            ->get();

        foreach ($clients as $client) {
            try {
                if ($client->opted_out_at !== null || ($client->followup_count ?? 0) >= 3) {
                    $this->logAutomation($client, 'blocked', null, 'opted_out_or_limit_reached');
                    continue;
                }

                $msg = "⏰ Hola {$client->name}, notamos que tu pedido aún está pendiente de pago.\n\n"
                     . "Recuerda yapear al *" . IntentService::getYapeNumber() . "* (" . IntentService::getYapeHolder() . ") y enviarnos la captura para confirmar tu pedido. "
                     . "¡El stock es limitado! 🔥";

                if (!$client->canReceiveFreeformWhatsApp()) {
                    // Ventana de 24h cerrada, usar plantilla
                    $templateName = 'recordatorio_pago_1';
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
                    $waMsg->reply($client, $msg);
                }

                $client->update([
                    'last_interaction_at' => now(),
                    'last_followup_at' => now(),
                    'followup_count' => ($client->followup_count ?? 0) + 1,
                ]);
                $this->logAutomation($client, 'sent', $msg);

                Log::info("Payment reminder sent to {$client->phone}");
            } catch (\Exception $e) {
                Log::error("PaymentReminderJob error for {$client->phone}: " . $e->getMessage());
            }
        }
    }

    protected function logAutomation(Client $client, string $status, ?string $message = null, ?string $reason = null): void
    {
        AutomationLog::create([
            'client_id' => $client->id,
            'type' => 'payment_reminder',
            'status' => $status,
            'message' => $message,
            'reason' => $reason,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
