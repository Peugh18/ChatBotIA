<?php

namespace App\Services\Business;

use App\Models\Product;
use App\Services\AI\IntentService;
use Illuminate\Support\Collection;

class ProductSearchService
{
    public function __construct(
        protected IntentService $intent
    ) {}

    /**
     * Product with stock — only IDs from this method are valid for sales.
     */
    public function findAvailable(int $productId): ?Product
    {
        return Product::with(['variants', 'category'])
            ->where('id', $productId)
            ->whereHas('variants', fn ($q) => $q->where('stock', '>', 0))
            ->first();
    }

    /**
     * Search inventory from free text + optional category filter in client state.
     */
    public function searchFromMessage(string $text, array $state = []): Collection
    {
        $filters = $this->intent->extractProductFilters($text);

        if (!empty($state['category_id'])) {
            $filters['category_id'] = (int) $state['category_id'];
        }

        $trimmed = trim($text);
        if (mb_strlen($trimmed) >= 2 && empty($filters['name']) && empty($filters['color'])) {
            $filters['query'] = $trimmed;
        }

        return $this->search($filters);
    }

    /**
     * Map image-analysis attributes to catalog search.
     */
    public function searchFromImageAttributes(array $attributes): Collection
    {
        $filters = [];
        foreach (['name', 'color', 'size', 'category'] as $key) {
            if (!empty($attributes[$key])) {
                $filters[$key] = $attributes[$key];
            }
        }
        if (!empty($attributes['keywords']) && is_array($attributes['keywords'])) {
            $filters['query'] = implode(' ', $attributes['keywords']);
        }

        return $this->search($filters);
    }
    /**
     * Dynamic multi-filter product search.
     * Filters: name, category, min_price, max_price, color, size
     */
    public function search(array $filters = []): Collection
    {
        $query = Product::with(['variants', 'category'])
            ->whereHas('variants', fn($q) => $q->where('stock', '>', 0));

        if (!empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (!empty($filters['query'])) {
            $q = $filters['query'];
            $query->where(fn ($sq) => $sq
                ->where('name', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")
                ->orWhere('sku', 'like', "%{$q}%"));
        }

        if (!empty($filters['name'])) {
            $q = $filters['name'];
            $query->where(fn ($sq) => $sq
                ->where('name', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%"));
        }

        if (!empty($filters['category'])) {
            $query->whereHas('category', fn($q) =>
                $q->where('name', 'like', '%' . $filters['category'] . '%')
            );
        }

        if (!empty($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (!empty($filters['color'])) {
            $query->whereHas('variants', fn($q) =>
                $q->where('color', 'like', '%' . $filters['color'] . '%')
                  ->where('stock', '>', 0)
            );
        }

        if (!empty($filters['size'])) {
            $query->whereHas('variants', fn($q) =>
                $q->where('size', 'like', '%' . $filters['size'] . '%')
                  ->where('stock', '>', 0)
            );
        }

        return $query->orderByDesc('sales_count')->limit(6)->get();
    }

    /**
     * Format a products collection into a readable string for the AI prompt.
     */
    public function formatForPrompt(Collection $products): string
    {
        if ($products->isEmpty()) {
            return 'No se encontraron productos con stock disponible.';
        }

        return $products->map(function ($product) {
            $variantLines = $product->variants
                ->where('stock', '>', 0)
                ->map(fn($v) => "    • {$v->color} / T:{$v->size} ({$v->stock} uds) [ID_VAR:{$v->id}]")
                ->join("\n");

            $discount = '';
            if (!empty($product->discount_percent) && $product->discount_percent > 0) {
                $finalPrice = $product->price * (1 - $product->discount_percent / 100);
                $discount   = " 🔥 -{$product->discount_percent}% → S/ " . number_format($finalPrice, 2);
            }

            return "*{$product->name}* [ID:{$product->id}]\n"
                 . "  Precio: S/ {$product->price}{$discount}\n"
                 . "  {$product->description}\n"
                 . "  Stock:\n{$variantLines}";
        })->join("\n\n---\n\n");
    }

    /**
     * Full catalog for the AI system prompt context.
     */
    public function getCatalogForContext(int $maxProducts = 15): string
    {
        $products = Product::with(['variants', 'category'])
            ->whereHas('variants', fn ($q) => $q->where('stock', '>', 0))
            ->orderByDesc('sales_count')
            ->limit($maxProducts)
            ->get();

        if ($products->isEmpty()) {
            return 'INVENTARIO VACÍO: no hay productos con stock. No ofrezcas ningún producto; pide al cliente esperar o escalar a asesor.';
        }

        return $this->formatForPrompt($products);
    }

    public function formatProductListForChat(Collection $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }

        return $products->map(function ($product) {
            $price = $this->effectivePrice($product);
            return "• *{$product->name}* — S/ " . number_format($price, 2) . " [ID:{$product->id}]";
        })->join("\n");
    }

    protected function effectivePrice(Product $product): float
    {
        if (!empty($product->discount_percent) && $product->discount_percent > 0) {
            return round((float) $product->price * (1 - $product->discount_percent / 100), 2);
        }

        return (float) $product->price;
    }

    /**
     * Top selling products for dashboard.
     */
    public function getTopSelling(int $limit = 5): Collection
    {
        return Product::with(['variants'])
            ->orderByDesc('sales_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Products similar by category (excluding given product).
     */
    public function getSimilar(int $productId, int $limit = 3): Collection
    {
        $product = Product::find($productId);
        if (!$product) return collect();

        return Product::with(['variants'])
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $productId)
            ->whereHas('variants', fn($q) => $q->where('stock', '>', 0))
            ->orderByDesc('sales_count')
            ->limit($limit)
            ->get();
    }
}
