<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── products ──────────────────────────────────────────────────────────
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('sales_count')->default(0)->after('ai_tags');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('sales_count');
        });

        // ── clients ───────────────────────────────────────────────────────────
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('budget_estimate', 10, 2)->nullable()->after('state');
            $table->json('preferences')->nullable()->after('budget_estimate');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sales_count', 'discount_percent']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['budget_estimate', 'preferences']);
        });
    }
};
