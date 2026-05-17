<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\WhatsAppService;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AbandonedCartFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WhatsAppService $wa, GeminiService $gemini): void
    {
        $twoHoursAgo = now()->subHours(2);

        $clients = Client::whereIn('status', ['CONSULTANDO', 'INTERESADO'])
            ->where('last_interaction_at', '<', $twoHoursAgo)
            ->whereNotNull('phone')
            ->get();

        foreach ($clients as $client) {
            try {
                $prompt = "Eres Roma de Roma Store. El cliente {$client->name} estuvo interesado pero no finalizó su compra hace más de 2 horas. "
                    . "Escríbele un mensaje CORTO, amigable y con urgencia natural (sin ser invasivo) para recuperarlo. "
                    . "Máximo 2 oraciones. Solo el texto del mensaje, sin JSON.";

                $message = $gemini->chat($prompt);

                if ($message) {
                    $wa->sendMessage($client->phone, $message);
                    $client->update([
                        'last_interaction_at' => now(),
                        'status'              => 'INTERESADO',
                    ]);
                    Log::info("Abandoned cart follow-up sent to {$client->phone}");
                }
            } catch (\Exception $e) {
                Log::error("AbandonedCartFollowUpJob error for {$client->phone}: " . $e->getMessage());
            }
        }
    }
}
