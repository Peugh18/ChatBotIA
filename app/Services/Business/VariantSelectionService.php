<?php

namespace App\Services\Business;

use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Log;
use App\Services\AI\GeminiService;

class VariantSelectionService
{
    protected GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Matches a variant from text using AI fuzzy matching.
     */
    public function matchVariantFromText(Product $product, string $text): ?ProductVariant
    {
        $variants = $product->variants->filter(fn($v) => $v->stock > 0);

        if ($variants->isEmpty()) {
            return null;
        }

        // Try local matching first
        $localMatch = $this->matchVariantLocally($text, $product);
        if ($localMatch) {
            return $localMatch;
        }

        // Fallback to AI matching
        return $this->matchVariantWithAI($product, $text, $variants);
    }

    /**
     * Local fuzzy matching for color and size.
     */
    protected function matchVariantLocally(string $text, Product $product): ?ProductVariant
    {
        $cleanText = strtolower(trim($text));
        $variants = $product->variants->filter(fn($v) => $v->stock > 0);

        foreach ($variants as $variant) {
            $colorMatch = str_contains($cleanText, strtolower($variant->color));
            $sizeMatch = str_contains($cleanText, strtolower($variant->size));

            if ($colorMatch && $sizeMatch) {
                return $variant;
            }

            // Try color only
            if ($colorMatch && !$sizeMatch) {
                // Check if any variant has this color
                $colorVariants = $variants->filter(fn($v) => strtolower($v->color) === strtolower($variant->color));
                if ($colorVariants->count() === 1) {
                    return $variant;
                }
            }

            // Try size only
            if (!$colorMatch && $sizeMatch) {
                $sizeVariants = $variants->filter(fn($v) => strtolower($v->size) === strtolower($variant->size));
                if ($sizeVariants->count() === 1) {
                    return $variant;
                }
            }
        }

        return null;
    }

    /**
     * AI-powered variant matching as fallback.
     */
    protected function matchVariantWithAI(Product $product, string $text, $variants): ?ProductVariant
    {
        $variantList = $variants->map(fn($v) => "{$v->color} {$v->size} (stock: {$v->stock})")->join(', ');

        $prompt = "Eres Roma de Roma Store. El cliente escribió: \"{$text}\"\n\n"
            . "Tenemos disponibles: {$variantList}\n\n"
            . "Responde SOLO con el color y talla exactos que el cliente quiere, en formato \"color talla\". "
            . "Si no hay coincidencia, responde \"NO_MATCH\".";

        try {
            $response = $this->geminiService->chat($prompt);
            
            if (!$response || $response === 'NO_MATCH') {
                return null;
            }

            $response = strtolower(trim($response));
            
            foreach ($variants as $variant) {
                $variantStr = strtolower("{$variant->color} {$variant->size}");
                if (str_contains($response, strtolower($variant->color)) && str_contains($response, strtolower($variant->size))) {
                    return $variant;
                }
            }
        } catch (\Exception $e) {
            Log::error('AI variant matching failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Format available variants for customer display.
     */
    public function formatVariantsForCustomer(Product $product): string
    {
        $variants = $product->variants->filter(fn($v) => $v->stock > 0);
        
        if ($variants->isEmpty()) {
            return 'agotado';
        }

        $byColor = [];
        foreach ($variants as $v) {
            $color = $v->color;
            if (!isset($byColor[$color])) {
                $byColor[$color] = [];
            }
            $byColor[$color][] = $v->size;
        }

        $formatted = [];
        foreach ($byColor as $color => $sizes) {
            $formatted[] = "*{$color}* en " . implode(', ', array_unique($sizes));
        }

        return implode(' | ', $formatted);
    }
}
