<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_progress_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_number');
            $table->string('report_type')->default('initial');
            // Draft/final lifecycle (PRD FR-10): created as draft on first In Progress,
            // finalized by the technician's first progress entry.
            $table->string('status')->default('draft');
            $table->string('title')->nullable();
            $table->text('problem_summary')->nullable();
            $table->text('work_performed')->nullable();
            $table->text('findings')->nullable();
            $table->text('next_steps')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->string('document_path')->nullable();
            $table->timestamps();

            // Idempotency for the initial report (PRD §7.6).
            $table->unique(['ticket_id', 'report_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_progress_reports');
    }
};
