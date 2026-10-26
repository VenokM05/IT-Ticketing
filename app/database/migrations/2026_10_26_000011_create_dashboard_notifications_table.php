<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_notifications', function (Blueprint $table) {
            $table->id();
            // Per-user read state is mandatory (PRD §7.8): one row per authorized recipient.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            // Distinguishing id for repeatable event types (status transitions reference
            // ticket_status_histories.id) so the unique key stays meaningful (PRD §7.8).
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('title');
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type', 'ticket_id', 'ref_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_notifications');
    }
};
