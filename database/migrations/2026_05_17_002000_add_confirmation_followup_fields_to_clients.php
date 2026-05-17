<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'confirmation_requested_at')) {
                $table->timestamp('confirmation_requested_at')->nullable()->after('last_interaction_at');
            }
            if (!Schema::hasColumn('clients', 'followup_3_sent_at')) {
                $table->timestamp('followup_3_sent_at')->nullable()->after('confirmation_requested_at');
            }
            if (!Schema::hasColumn('clients', 'followup_15_sent_at')) {
                $table->timestamp('followup_15_sent_at')->nullable()->after('followup_3_sent_at');
            }
            if (!Schema::hasColumn('clients', 'followup_stopped_at')) {
                $table->timestamp('followup_stopped_at')->nullable()->after('followup_15_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['confirmation_requested_at', 'followup_3_sent_at', 'followup_15_sent_at', 'followup_stopped_at'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
