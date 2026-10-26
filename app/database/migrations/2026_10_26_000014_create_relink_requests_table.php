<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relink_requests', function (Blueprint $table) {
            $table->id();
            // The new WhatsApp number the sender is writing from, normalized E.164 (§9.3).
            $table->string('requested_number');
            // Existing employee the Administrator matches it to — only set on approval.
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('source_message_id')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('requested_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relink_requests');
    }
};
