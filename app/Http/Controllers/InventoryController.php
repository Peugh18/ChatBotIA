<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController extends Controller
{
    public function index()
    {
        $products = Product::with(['category', 'variants'])->latest()->get();
        $categories = Category::orderBy('name')->get();

        return Inertia::render('Inventory', [
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|unique:products,sku',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'category_id' => 'required|exists:categories,id',
            'ai_tags' => 'nullable|array',
            'colors' => 'nullable|array',
            'colors.*.color' => 'required|string',
            'colors.*.image' => 'nullable|image|max:2048',
            'colors.*.image_url' => 'nullable|string',
            'colors.*.sizes' => 'required|array',
            'colors.*.sizes.*.size' => 'required|string',
            'colors.*.sizes.*.stock' => 'required|integer|min:0',
        ]);

        $sku = $validated['sku'] ?? null;
        if (empty($sku)) {
            $sku = self::generateUniqueSku($validated['name']);
        }

        $product = Product::create([
            'name' => $validated['name'],
            'sku' => $sku,
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'discount_percent' => $validated['discount_percent'] ?? 0,
            'image_url' => null,
            'category_id' => $validated['category_id'],
            'ai_tags' => $validated['ai_tags'] ?? [],
        ]);

        $firstVariantImageUrl = null;
        foreach ($validated['colors'] ?? [] as $index => $colorData) {
            $colorImageUrl = null;
            if ($request->hasFile("colors.{$index}.image")) {
                $path = $request->file("colors.{$index}.image")->store('variants', 'public');
                $colorImageUrl = '/storage/' . $path;
            }

            if (empty($firstVariantImageUrl) && !empty($colorImageUrl)) {
                $firstVariantImageUrl = $colorImageUrl;
            }

            foreach ($colorData['sizes'] ?? [] as $sizeData) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'color' => $colorData['color'],
                    'size' => $sizeData['size'],
                    'stock' => $sizeData['stock'],
                    'image_url' => $colorImageUrl,
                ]);
            }
        }

        if (!empty($firstVariantImageUrl)) {
            $product->update(['image_url' => $firstVariantImageUrl]);
        }

        return redirect()->route('inventory')->with('success', 'Producto creado exitosamente.');
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|unique:products,sku,' . $product->id,
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'category_id' => 'required|exists:categories,id',
            'ai_tags' => 'nullable|array',
            'colors' => 'nullable|array',
            'colors.*.color' => 'required|string',
            'colors.*.image' => 'nullable|image|max:2048',
            'colors.*.image_url' => 'nullable|string',
            'colors.*.sizes' => 'required|array',
            'colors.*.sizes.*.size' => 'required|string',
            'colors.*.sizes.*.stock' => 'required|integer|min:0',
        ]);

        $sku = $validated['sku'] ?? $product->sku;
        if (empty($sku)) {
            $sku = self::generateUniqueSku($validated['name']);
        }

        $product->update([
            'name' => $validated['name'],
            'sku' => $sku,
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'discount_percent' => $validated['discount_percent'] ?? 0,
            'category_id' => $validated['category_id'],
            'ai_tags' => $validated['ai_tags'] ?? [],
        ]);

        // Replace variants
        $product->variants()->delete();
        $firstVariantImageUrl = null;
        foreach ($validated['colors'] ?? [] as $index => $colorData) {
            $colorImageUrl = $colorData['image_url'] ?? null;
            if ($request->hasFile("colors.{$index}.image")) {
                $path = $request->file("colors.{$index}.image")->store('variants', 'public');
                $colorImageUrl = '/storage/' . $path;
            }

            if (empty($firstVariantImageUrl) && !empty($colorImageUrl)) {
                $firstVariantImageUrl = $colorImageUrl;
            }

            foreach ($colorData['sizes'] ?? [] as $sizeData) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'color' => $colorData['color'],
                    'size' => $sizeData['size'],
                    'stock' => $sizeData['stock'],
                    'image_url' => $colorImageUrl,
                ]);
            }
        }

        // Always update product cover image to the first variant's image url
        $product->update(['image_url' => $firstVariantImageUrl]);

        return redirect()->route('inventory')->with('success', 'Producto actualizado exitosamente.');
    }

    public function destroy(Product $product)
    {
        $product->variants()->delete();
        $product->delete();

        return redirect()->route('inventory')->with('success', 'Producto eliminado.');
    }

    private static function generateUniqueSku($name)
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 3));
        if (strlen($prefix) < 3) {
            $prefix = 'ROM';
        }
        $sku = $prefix . '-' . strtoupper(bin2hex(random_bytes(3))); // ROM-AB12CD
        while (Product::where('sku', $sku)->exists()) {
            $sku = $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
        }
        return $sku;
    }
}
