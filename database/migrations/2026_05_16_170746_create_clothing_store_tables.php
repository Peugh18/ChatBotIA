<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('image_url')->nullable();
            $table->json('ai_tags')->nullable();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('color');
            $table->string('size');
            $table->integer('stock')->default(0);
            $table->string('image_url')->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('name')->nullable();
            $table->enum('status', [
                'NUEVO', 'INTERESADO', 'CONSULTANDO', 'ESPERANDO PAGO', 
                'PAGO RECIBIDO', 'EN PREPARACIÓN', 'EN CAMINO', 
                'FINALIZADO', 'ABANDONADO', 'NECESITA ASESOR'
            ])->default('NUEVO');
            $table->enum('priority', ['ALTA', 'MEDIA', 'BAJA'])->default('BAJA');
            $table->timestamp('last_interaction_at')->nullable();
            $table->json('state')->nullable(); // Para flujo de venta IA
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->boolean('from_me')->default(false);
            $table->text('body')->nullable();
            $table->string('type')->default('text'); // text, image, audio, document
            $table->string('media_url')->nullable();
            $table->string('meta_message_id')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['PENDIENTE', 'PAGADO', 'CANCELADO', 'ENVIADO', 'ENTREGADO'])->default('PENDIENTE');
            $table->decimal('total', 10, 2);
            $table->text('shipping_address')->nullable();
            $table->string('shipping_method')->nullable(); // Motorizado, Shalom, etc.
            $table->string('payment_receipt_url')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
