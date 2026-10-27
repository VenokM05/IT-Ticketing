<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings / classification audit trail (PRD Phase 8, Rec #10). A compact,
 * append-only record of who changed what (WhatsApp credentials, companies,
 * ticket priority/status) with an optional JSON payload. Actor is nullable so a
 * row still lands if the user is later removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->string('event');
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
