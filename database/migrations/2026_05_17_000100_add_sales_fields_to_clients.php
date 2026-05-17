<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'assigned_user_id')) {
                $table->foreignId('assigned_user_id')->nullable()->after('priority')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('clients', 'first_response_at')) {
                $table->timestamp('first_response_at')->nullable()->after('last_interaction_at');
            }
            if (!Schema::hasColumn('clients', 'lead_score')) {
                $table->unsignedTinyInteger('lead_score')->default(0)->after('priority');
            }
            if (!Schema::hasColumn('clients', 'lifetime_value')) {
                $table->decimal('lifetime_value', 12, 2)->default(0)->after('lead_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'assigned_user_id')) {
                $table->dropConstrainedForeignId('assigned_user_id');
            }
            foreach (['first_response_at', 'lead_score', 'lifetime_value'] as $col) {
                if (Schema::hasColumn('clients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
