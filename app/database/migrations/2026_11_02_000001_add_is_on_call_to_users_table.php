<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-call flag for auto-assignment routing: when several technicians can handle a
 * ticket, the on-call one is preferred. Defaults off so existing accounts keep the
 * current least-loaded behaviour until an admin opts them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_on_call')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_on_call');
        });
    }
};
