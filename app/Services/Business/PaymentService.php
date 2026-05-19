<?php

namespace App\Services\Business;

use App\Models\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\AI\GeminiService;
use App\Services\AI\IntentService;

class PaymentService
{
    protected GeminiService $geminiService;
    protected IntentService $intent;

    public function __construct(GeminiService $geminiService, IntentService $intent)
    {
        $this->geminiService = $geminiService;
        $this->intent = $intent;
    }

    /**
     * Detect payment method from text.
     */
    public function detectPaymentMethod(string $text): ?string
    {
        $cleanText = strtolower(trim($text));

        if (str_contains($cleanText, 'yape') || str_contains($cleanText, 'yap')) {
            return 'yape';
        }

        if (str_contains($cleanText, 'tarjeta') || str_contains($cleanText, 'card') || str_contains($cleanText, 'link')) {
            return 'tarjeta';
        }

        return null;
    }

    /**
     * Build Yape-only payment message.
     */
    public function buildYapeOnlyMessage(float $amount): string
    {
        $yapeNumber = IntentService::getYapeNumber();
        $yapeHolder = IntentService::getYapeHolder();

        return "💳 *Método de pago: Yape*\n\n"
            . "El yape es este mismo número de WhatsApp ({$yapeNumber}) a nombre de {$yapeHolder}, nos envías la captura y confirmas si te enviamos por shalom o motorizado.";
    }

    /**
     * Build Card/Link payment message.
     */
    public function buildCardOnlyMessage(float $amount): string
    {
        return "💳 *Datos para Link de Pago o tarjeta de crédito:*\n\n"
            . "✅ Nombre completo:\n"
            . "✅ Correo electrónico:\n"
            . "✅ Número de Celular:\n"
            . "✅ Monto: S/ " . number_format($amount, 2) . "\n\n"
            . "_Por favor, envíame estos datos para que un agente especializado te genere el link de pago._ ✨";
    }

    /**
     * Extract payment amount from receipt image using AI.
     */
    public function extractAmountFromReceipt(string $imageBase64): ?float
    {
        try {
            $prompt = "Eres un asistente financiero. Analiza esta imagen de un comprobante de pago Yape "
                . "y extrae SOLAMENTE el monto numérico pagado. "
                . "Responde SOLO con el número (ejemplo: 59.90 o 150.00). "
                . "Si no hay monto claro, responde 0.";

            $response = $this->geminiService->analyzeImage($imageBase64, $prompt);

            if (!$response) {
                return null;
            }

            $amount = (float) preg_replace('/[^0-9.]/', '', $response);
            return $amount > 0 ? $amount : null;
        } catch (\Exception $e) {
            Log::error('Failed to extract amount from receipt: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Save payment receipt image to storage.
     */
    public function savePaymentReceipt(string $binary, string $phone): string
    {
        $filename = "payments/{$phone}_" . time() . ".jpg";
        Storage::disk('public')->put($filename, $binary);
        return Storage::url($filename);
    }

    /**
     * Get expected payment amount from client state.
     */
    public function getExpectedPaymentAmount(array $state): float
    {
        $productTotal = $this->getOrderProductTotal($state);
        $shippingCost = $state['shipping_cost'] ?? 0;
        $deliveryCost = $state['delivery_cost'] ?? 0;

        return $productTotal + $shippingCost + $deliveryCost;
    }

    /**
     * Calculate product total from state.
     */
    protected function getOrderProductTotal(array $state): float
    {
        if (!isset($state['variant_id'])) {
            return 0;
        }

        $variant = \App\Models\ProductVariant::with('product')->find($state['variant_id']);
        if (!$variant) {
            return 0;
        }

        $quantity = $state['quantity'] ?? 1;
        return $variant->product->price * $quantity;
    }
}
