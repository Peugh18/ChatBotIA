<?php

namespace App\Services\Business;

use App\Models\DeliveryZone;
use App\Services\AI\IntentService;

class DeliveryService
{
    /**
     * Find delivery zone from text.
     */
    public function findDeliveryZoneFromText(string $text): ?DeliveryZone
    {
        return DeliveryZone::findByName($text);
    }

    /**
     * Delivery zones with motorizado/shalom prices for AI context (inventory-backed).
     */
    public function formatZonesForPrompt(int $limit = 40): string
    {
        $zones = DeliveryZone::where('active', true)
            ->orderBy('district')
            ->limit($limit)
            ->get();

        if ($zones->isEmpty()) {
            return 'No hay distritos configurados. Si preguntan delivery en Lima, pide el distrito exacto.';
        }

        return $zones->map(fn (DeliveryZone $z) =>
            "• {$z->district}: Motorizado S/ " . number_format((float) $z->motorizado_cost, 2)
            . ' | Shalom S/ ' . number_format((float) $z->shalom_cost, 2)
        )->join("\n");
    }

    /**
     * Normalize shipping choice from text.
     */
    public function normalizeShippingChoice(string $text): ?string
    {
        $cleanText = strtolower(trim($text));

        if (str_contains($cleanText, 'motorizado') || str_contains($cleanText, 'motor') || str_contains($cleanText, 'moto')) {
            return 'Motorizado';
        }

        if (str_contains($cleanText, 'shalom') || str_contains($cleanText, 'envio') || str_contains($cleanText, 'envío')) {
            return 'Shalom';
        }

        return null;
    }

    /**
     * Get Shalom shipping cost.
     */
    public function getShalomCost(): float
    {
        return IntentService::getShalomLima();
    }

    /**
     * Calculate total delivery cost based on state.
     */
    public function calculateDeliveryCost(array $state): float
    {
        $shipping = $state['shipping'] ?? null;

        if ($shipping === 'Motorizado') {
            return $state['delivery_cost'] ?? 0;
        }

        if ($shipping === 'Shalom') {
            return $this->getShalomCost();
        }

        return 0;
    }

    /**
     * Build delivery info message for motorizado.
     */
    public function buildMotorizadoMessage(DeliveryZone $zone, float $productTotal): string
    {
        return "El delivery para *{$zone->district}* es de *S/ " . number_format($zone->motorizado_cost, 2) . "*.\n\n"
            . "El pago del motorizado se cancela al recibir el pedido. Entregas: " . IntentService::getMotorizadoWindow() . " 🛵\n\n"
            . "El producto queda en *S/ " . number_format($productTotal, 2) . "*. ¿Prefieres pagar por *Yape* o *tarjeta/link*, hermosa?";
    }

    /**
     * Build delivery info message for Shalom.
     */
    public function buildShalomMessage(float $productTotal): string
    {
        $shalomCost = $this->getShalomCost();
        $total = $productTotal + $shalomCost;

        return "Por *Shalom* el costo de envío es de *S/ 10 para Lima* y *S/ 12 para provincia* en promedio, y llega de 1 a 3 días hábiles. 🚚\n\n"
            . "El total a pagar ahora sería *S/ " . number_format($total, 2) . "*. ¿Prefieres *Yape* o *tarjeta/link*, hermosa?";
    }
}
