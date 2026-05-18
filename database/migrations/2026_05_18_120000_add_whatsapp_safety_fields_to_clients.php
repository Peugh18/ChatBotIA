<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'last_customer_message_at')) {
                $table->timestamp('last_customer_message_at')->nullable()->after('last_interaction_at');
            }
            if (!Schema::hasColumn('clients', 'last_followup_at')) {
                $table->timestamp('last_followup_at')->nullable()->after('followup_stopped_at');
            }
            if (!Schema::hasColumn('clients', 'followup_count')) {
                $table->unsignedSmallInteger('followup_count')->default(0)->after('last_followup_at');
            }
            if (!Schema::hasColumn('clients', 'opted_out_at')) {
                $table->timestamp('opted_out_at')->nullable()->after('followup_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['last_customer_message_at', 'last_followup_at', 'followup_count', 'opted_out_at'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
