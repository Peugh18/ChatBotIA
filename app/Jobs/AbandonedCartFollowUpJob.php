<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\AutomationLog;
use App\Models\Message;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AbandonedCartFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WhatsAppService $wa): void
    {
        $twoHoursAgo = now()->subHours(2);

        $clients = Client::whereIn('status', ['CONSULTANDO', 'INTERESADO'])
            ->where('last_interaction_at', '<', $twoHoursAgo)
            ->whereNull('opted_out_at')
            ->whereNotNull('phone')
            ->get();

        foreach ($clients as $client) {
            try {
                if (!$client->canReceiveAutomation()) {
                    $this->logAutomation($client, 'blocked', null, 'outside_24h_or_opted_out_or_limit');
                    Log::info("Abandoned cart follow-up skipped for {$client->phone}: outside 24h window, opted out, or follow-up limit reached.");
                    continue;
                }

                $message = "Hermosa, ¿te separo la prenda que estabas viendo? 💕\n\n"
                    . "El stock se mueve rápido por TikTok Live, pero te ayudo a confirmarlo en un ratito.";

                $wa->sendMessage($client->phone, $message);
                Message::create([
                    'client_id' => $client->id,
                    'from_me' => true,
                    'body' => $message,
                    'type' => 'text',
                ]);
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
