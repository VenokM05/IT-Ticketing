<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokenized self-service lookup (PRD Phase 10, Rec #13). A capability link handed
 * to a public requester at intake — no password, no new auth surface. The opaque
 * random token (not the ticket id) is the only way to reach the read-only status
 * page and post a follow-up comment. Tokens carry an optional expiry; an expired
 * or unknown token is indistinguishable from a miss (404), so nothing leaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tokens');
    }
};
