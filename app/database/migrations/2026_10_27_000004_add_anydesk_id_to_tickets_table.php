<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional AnyDesk remote-desktop ID captured at intake (public web form and the
 * admin on-behalf form) so technicians can connect without chasing the requester.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('anydesk_id', 60)->nullable()->after('requester_position');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('anydesk_id');
        });
    }
};
