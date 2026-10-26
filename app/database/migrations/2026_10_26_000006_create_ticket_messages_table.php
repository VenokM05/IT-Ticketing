<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction');
            $table->string('channel')->default('whatsapp');
            $table->string('message_type')->default('text');
            $table->text('body')->nullable();
            $table->string('whatsapp_message_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('ticket_id');
            // Dedup helper for outbound reconciliation by WhatsApp message ID.
            $table->index('whatsapp_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
