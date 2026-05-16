<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Category;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $polos = Category::where('name', 'Polos')->first();
        $jeans = Category::where('name', 'Jeans')->first();

        $p1 = Product::create([
            'category_id' => $polos->id,
            'name' => 'Polo Oversize Roma Black',
            'sku' => 'POL-OVR-BLK',
            'description' => 'Polo de algodón premium con corte oversize. Color negro intenso.',
            'price' => 45.00,
            'image_url' => 'https://via.placeholder.com/400x600?text=Polo+Oversize+Black',
            'ai_tags' => ['polo', 'negro', 'oversize', 'algodon']
        ]);

        ProductVariant::create(['product_id' => $p1->id, 'color' => 'Negro', 'size' => 'M', 'stock' => 10]);
        ProductVariant::create(['product_id' => $p1->id, 'color' => 'Negro', 'size' => 'L', 'stock' => 5]);

        $p2 = Product::create([
            'category_id' => $jeans->id,
            'name' => 'Jean Slim Fit Blue Classic',
            'sku' => 'JNS-SLM-BLU',
            'description' => 'Jean slim fit color azul clásico con desgastes ligeros.',
            'price' => 89.90,
            'image_url' => 'https://via.placeholder.com/400x600?text=Jean+Slim+Blue',
            'ai_tags' => ['jean', 'azul', 'slim fit']
        ]);

        ProductVariant::create(['product_id' => $p2->id, 'color' => 'Azul', 'size' => '30', 'stock' => 8]);
        ProductVariant::create(['product_id' => $p2->id, 'color' => 'Azul', 'size' => '32', 'stock' => 12]);
        ProductVariant::create(['product_id' => $p2->id, 'color' => 'Azul', 'size' => '34', 'stock' => 4]);

        $p3 = Product::create([
            'category_id' => $polos->id,
            'name' => 'Polo Roma Essential White',
            'sku' => 'POL-ESS-WHT',
            'description' => 'Polo básico blanco de cuello redondo, ideal para diario.',
            'price' => 35.00,
            'image_url' => 'https://via.placeholder.com/400x600?text=Polo+Essential+White',
            'ai_tags' => ['polo', 'blanco', 'basico']
        ]);

        ProductVariant::create(['product_id' => $p3->id, 'color' => 'Blanco', 'size' => 'S', 'stock' => 15]);
        ProductVariant::create(['product_id' => $p3->id, 'color' => 'Blanco', 'size' => 'M', 'stock' => 20]);
    }
}
