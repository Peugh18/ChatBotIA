<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Message;
use App\Services\WhatsAppService;
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
                if ($this->clientRespondedAfterConfirmation($client)) {
                    $this->stopFollowUps($client);
                    continue;
                }

                if ($this->shouldSendThreeMinuteFollowUp($client)) {
                    $this->send($wa, $client, 'Hermosa, nos confirmas si vas a realizar el pedido por favor 💛');
                    $client->forceFill(['followup_3_sent_at' => now()])->save();
                    Log::info("3-minute confirmation follow-up sent to {$client->phone}");
                    continue;
                }

                if ($this->shouldSendFifteenMinuteFollowUp($client)) {
                    $this->send($wa, $client, 'Muchas gracias hermosa, cualquier cosita si te animas más tarde nos escribes. Que tengas un gran día 🤗🤗');
                    $client->forceFill([
                        'followup_15_sent_at' => now(),
                        'followup_stopped_at' => now(),
                        'status' => 'ABANDONADO',
                        'priority' => 'BAJA',
                    ])->save();
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
}
