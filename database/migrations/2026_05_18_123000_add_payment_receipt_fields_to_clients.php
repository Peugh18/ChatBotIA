<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'payment_receipt_url')) {
                $table->string('payment_receipt_url')->nullable()->after('preferences');
            }
            if (!Schema::hasColumn('clients', 'paid_amount')) {
                $table->decimal('paid_amount', 8, 2)->nullable()->after('payment_receipt_url');
            }
            if (!Schema::hasColumn('clients', 'payment_verified_by')) {
                $table->unsignedBigInteger('payment_verified_by')->nullable()->after('paid_amount');
            }
            if (!Schema::hasColumn('clients', 'payment_verified_at')) {
                $table->timestamp('payment_verified_at')->nullable()->after('payment_verified_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['payment_receipt_url', 'paid_amount', 'payment_verified_by', 'payment_verified_at'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
