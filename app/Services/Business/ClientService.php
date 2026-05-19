<?php

namespace App\Services\Business;

use App\Events\ClientStatusUpdated;
use App\Events\MessageReceived;
use App\Models\Client;
use App\Models\Message;
use App\Models\Tag;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Client Service - Gestión de clientes, tags, notas y respuestas rápidas.
 */
class ClientService
{
    /**
     * Update client data and broadcast status change.
     */
    public function updateClientAndBroadcast(Client $client, array $data): void
    {
        $client->update($data);
        
        // Only broadcast when status or priority changed (avoids noise on state-only updates)
        if (isset($data['status']) || isset($data['priority'])) {
            broadcast(new ClientStatusUpdated($client))->toOthers();
        }
    }

    /**
     * Touch first response timestamp (SLA tracking).
     */
    public function touchFirstResponseAt(Client $client): void
    {
        if (!Schema::hasColumn('clients', 'first_response_at')) return;
        if (!empty($client->first_response_at)) return;
        $client->forceFill(['first_response_at' => now()])->save();
    }

    /**
     * Sync AI-suggested tags to client.
     */
    public function syncAiTags(Client $client, array $toAdd, array $toRemove): void
    {
        $toAdd    = array_filter(array_map('trim', $toAdd));
        $toRemove = array_filter(array_map('trim', $toRemove));

        if (empty($toAdd) && empty($toRemove)) return;

        // Get available tag IDs
        $availableTags = Tag::all()->keyBy('name');
        
        // Add new tags
        foreach ($toAdd as $tagName) {
            $tag = $availableTags->get($tagName);
            if ($tag && !$client->tags()->where('tags.id', $tag->id)->exists()) {
                $client->tags()->attach($tag->id);
                Log::info("AI added tag '{$tagName}' to client {$client->phone}");
            }
        }

        // Remove tags
        foreach ($toRemove as $tagName) {
            $tag = $availableTags->get($tagName);
            if ($tag && $client->tags()->where('tags.id', $tag->id)->exists()) {
                $client->tags()->detach($tag->id);
                Log::info("AI removed tag '{$tagName}' from client {$client->phone}");
            }
        }
    }

    /**
     * Write AI-suggested internal note to client.
     */
    public function writeAiNote(Client $client, ?string $body): void
    {
        $body = is_string($body) ? trim($body) : '';
        if ($body === '' || mb_strlen($body) < 5) return;

        $client->notes()->create([
            'body' => $body,
            'created_by' => 'ai',
        ]);

        Log::info("AI added note to client {$client->phone}: " . mb_strimwidth($body, 0, 50, '…'));
    }

    /**
     * Build payment info message.
     */
    public function buildPaymentInfoMessage(?float $amount = null): string
    {
        $yape  = \App\Services\AI\IntentService::getYapeNumber();
        $owner = \App\Services\AI\IntentService::getYapeHolder();
        $amountText = $amount ? "Monto: *S/ " . number_format($amount, 2) . "*\n\n" : '';
        return "💸 *Métodos de pago*\n\n"
            . $amountText
            . "🔹 *Yape* a este mismo número: *{$yape}* ({$owner}).\n"
            . "   Envíame la captura y confirmamos en el acto. ⚡\n\n"
            . "🔹 *Tarjeta / Link de pago*: si prefieres pagar con tarjeta, "
            . "envíame estos datos y te genero el link:\n"
            . "   • Nombre completo\n"
            . "   • Correo electrónico\n"
            . "   • Número de celular\n"
            . "   • Monto a pagar";
    }
}
