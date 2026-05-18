<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\AutomationLog;
use App\Models\Message;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PostSaleFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WhatsAppService $wa): void
    {
        // Orders delivered in the last 24h that haven't had a post-sale message
        $orders = Order::with('client')
            ->where('status', 'ENTREGADO')
            ->where('updated_at', '>=', now()->subDay())
            ->where('updated_at', '<', now()->subHours(4))
            ->whereDoesntHave('client', fn($q) => $q->where('status', 'FINALIZADO'))
            ->get();

        foreach ($orders as $order) {
            $client = $order->client;
            if (!$client || empty($client->phone)) continue;

            try {
                if (!$client->canReceiveAutomation()) {
                    $this->logAutomation($client, 'blocked', null, 'outside_24h_or_opted_out_or_limit', ['order_id' => $order->id]);
                    Log::info("Post-sale follow-up skipped for {$client->phone}: outside 24h window, opted out, or follow-up limit reached.");
                    continue;
                }

                $msg = "¡Hola {$client->name}! 🎉 Esperamos que hayas recibido tu pedido y estés feliz con él.\n\n"
                     . "¿Quedaste contento/a con tu compra? Tu opinión nos importa mucho 💬\n"
                     . "Y si quieres ver nuestras novedades, solo escríbenos 'catálogo' aquí mismo. 🛍️";

                $wa->sendMessage($client->phone, $msg);
                Message::create([
                    'client_id' => $client->id,
                    'from_me' => true,
                    'body' => $msg,
                    'type' => 'text',
                ]);
                $client->update([
                    'status' => 'FINALIZADO',
                    'last_followup_at' => now(),
                    'followup_count' => ($client->followup_count ?? 0) + 1,
                ]);
                $this->logAutomation($client, 'sent', $msg, null, ['order_id' => $order->id]);

                Log::info("Post-sale follow-up sent to {$client->phone} for order #{$order->id}");
            } catch (\Exception $e) {
                Log::error("PostSaleFollowUpJob error for order #{$order->id}: " . $e->getMessage());
            }
        }
    }

    protected function logAutomation($client, string $status, ?string $message = null, ?string $reason = null, array $context = []): void
    {
        AutomationLog::create([
            'client_id' => $client->id,
            'type' => 'post_sale_followup',
            'status' => $status,
            'message' => $message,
            'reason' => $reason,
            'context' => $context,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
