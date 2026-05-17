<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PaymentReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WhatsAppService $wa): void
    {
        $oneHourAgo = now()->subHour();

        $clients = Client::where('status', 'ESPERANDO PAGO')
            ->where('last_interaction_at', '<', $oneHourAgo)
            ->whereNotNull('phone')
            ->get();

        foreach ($clients as $client) {
            try {
                $msg = "⏰ Hola {$client->name}, notamos que tu pedido aún está pendiente de pago.\n\n"
                     . "Recuerda yapear al *959166911* (José Urcia) y enviarnos la captura para confirmar tu pedido. "
                     . "¡El stock es limitado! 🔥";

                $wa->sendMessage($client->phone, $msg);
                $client->update(['last_interaction_at' => now()]);

                Log::info("Payment reminder sent to {$client->phone}");
            } catch (\Exception $e) {
                Log::error("PaymentReminderJob error for {$client->phone}: " . $e->getMessage());
            }
        }
    }
}
