<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

class ProductSearchService
{
    /**
     * Dynamic multi-filter product search.
     * Filters: name, category, min_price, max_price, color, size
     */
    public function search(array $filters = []): Collection
    {
        $query = Product::with(['variants', 'category'])
            ->whereHas('variants', fn($q) => $q->where('stock', '>', 0));

        if (!empty($filters['name'])) {
            $q = $filters['name'];
            $query->where(fn($sq) =>
                $sq->where('name', 'like', "%{$q}%")
                   ->orWhere('description', 'like', "%{$q}%")
            );
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
    public function getCatalogForContext(): string
    {
        $products = Product::with(['variants', 'category'])
            ->whereHas('variants', fn($q) => $q->where('stock', '>', 0))
            ->orderByDesc('sales_count')
            ->get();

        if ($products->isEmpty()) {
            return 'Catálogo vacío — no hay productos con stock.';
        }

        return $this->formatForPrompt($products);
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
