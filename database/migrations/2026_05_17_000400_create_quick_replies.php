<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quick_replies')) return;
        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('shortcut', 30)->unique();  // ej: /envio, /yape, /gracias
            $table->string('title');
            $table->text('body');
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
    }
};
