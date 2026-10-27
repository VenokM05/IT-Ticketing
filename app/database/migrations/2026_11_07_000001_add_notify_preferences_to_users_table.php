<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification preferences (PRD Phase 7, Rec #15): staff can opt in or
 * out of the email and WhatsApp delivery channels independently of the in-app
 * dashboard row, which is always written. Both default to on so existing users
 * keep receiving every channel until they opt down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_email')->default(true)->after('is_on_call');
            $table->boolean('notify_whatsapp')->default(true)->after('notify_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_email', 'notify_whatsapp']);
        });
    }
};
