<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('district');                // Display name, ej: "Miraflores"
            $table->string('slug')->unique();          // Normalized: "miraflores"
            $table->decimal('motorizado_cost', 6, 2);  // Costo en S/. para envío motorizado
            $table->decimal('shalom_cost', 6, 2)->default(10);   // Shalom Lima por defecto
            $table->string('region')->default('Lima'); // Lima | Provincia
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();         // Notas internas opcionales
            $table->timestamps();

            $table->index(['region', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
    }
};
