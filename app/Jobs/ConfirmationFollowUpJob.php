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

class ConfirmationFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WhatsAppService $wa): void
    {
        $clients = Client::whereNotNull('confirmation_requested_at')
            ->whereNull('followup_stopped_at')
            ->whereNotIn('status', ['ESPERANDO PAGO', 'PAGO RECIBIDO', 'EN PREPARACIÓN', 'EN CAMINO', 'FINALIZADO', 'ABANDONADO', 'NECESITA ASESOR'])
            ->whereNotNull('phone')
            ->get();

        foreach ($clients as $client) {
            try {
                if (!$client->canReceiveAutomation()) {
                    $this->logAutomation($client, 'blocked', null, 'outside_24h_or_opted_out_or_limit');
                    Log::info("Confirmation follow-up skipped for {$client->phone}: outside 24h window, opted out, or follow-up limit reached.");
                    $this->stopFollowUps($client);
                    continue;
                }

                if ($this->clientRespondedAfterConfirmation($client)) {
                    $this->logAutomation($client, 'blocked', null, 'client_replied_after_confirmation');
                    $this->stopFollowUps($client);
                    continue;
                }

                if ($this->shouldSendThreeMinuteFollowUp($client)) {
                    $body = 'Hermosa nos confirmas si vas a realizar el pedido por favor';
                    $this->send($wa, $client, $body);
                    $client->forceFill([
                        'followup_3_sent_at' => now(),
                        'last_followup_at' => now(),
                        'followup_count' => ($client->followup_count ?? 0) + 1,
                    ])->save();
                    $this->logAutomation($client, 'sent', $body, null, ['stage' => '3_minutes']);
                    Log::info("3-minute confirmation follow-up sent to {$client->phone}");
                    continue;
                }

                if ($this->shouldSendFifteenMinuteFollowUp($client)) {
                    $body = 'Muchas gracias hermosa, cualquier cosita si te animas más tarde nos escribes. Que tengas un gran día 🤗🤗';
                    $this->send($wa, $client, $body);
                    $client->forceFill([
                        'followup_15_sent_at' => now(),
                        'followup_stopped_at' => now(),
                        'last_followup_at' => now(),
                        'followup_count' => ($client->followup_count ?? 0) + 1,
                        'status' => 'ABANDONADO',
                        'priority' => 'BAJA',
                    ])->save();
                    $this->logAutomation($client, 'sent', $body, null, ['stage' => '15_minutes']);
                    Log::info("15-minute confirmation follow-up sent to {$client->phone}");
                }
            } catch (\Throwable $e) {
                Log::error("ConfirmationFollowUpJob error for {$client->phone}: " . $e->getMessage());
            }
        }
    }

    protected function shouldSendThreeMinuteFollowUp(Client $client): bool
    {
        return $client->followup_3_sent_at === null
            && $client->confirmation_requested_at !== null
            && $client->confirmation_requested_at->lte(now()->subMinutes(3));
    }

    protected function shouldSendFifteenMinuteFollowUp(Client $client): bool
    {
        return $client->followup_15_sent_at === null
            && $client->confirmation_requested_at !== null
            && $client->confirmation_requested_at->lte(now()->subMinutes(15));
    }

    protected function clientRespondedAfterConfirmation(Client $client): bool
    {
        return Message::where('client_id', $client->id)
            ->where('from_me', false)
            ->where('created_at', '>', $client->confirmation_requested_at)
            ->exists();
    }

    protected function stopFollowUps(Client $client): void
    {
        $client->forceFill(['followup_stopped_at' => now()])->save();
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
            'type' => 'confirmation_followup',
            'status' => $status,
            'message' => $message,
            'reason' => $reason,
            'context' => $context,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
