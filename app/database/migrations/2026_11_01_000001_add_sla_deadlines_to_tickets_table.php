<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA deadline columns (calendar-hour budgets per priority, from created_at).
 * Nullable so historical rows and any ticket created outside the SLA map simply
 * carry no deadline rather than a misleading one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('response_due_at')->nullable()->after('closed_at');
            $table->timestamp('resolve_due_at')->nullable()->after('response_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['response_due_at', 'resolve_due_at']);
        });
    }
};
