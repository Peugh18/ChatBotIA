<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the legacy `pedidos` table. Replaced by `orders` + `order_items`
 * (see clothing_store_tables migration). The Pedido model was unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pedidos');
    }

    public function down(): void
    {
        // Intentionally no-op: the original migration was removed.
    }
};
