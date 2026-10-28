<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dedicated email address on a public request. "requester_contact" stays the
 * primary channel IT replies on (phone or email), while this optional address is
 * where the intake receipt — ticket number plus tracking link — is mailed, and a
 * second key for recovering that link later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('requester_email', 255)->nullable()->after('requester_position');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('requester_email');
        });
    }
};
