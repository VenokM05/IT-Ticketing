<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canned responses (macros): reusable, pre-written snippets technicians insert
 * into progress entries and notes. Bodies may reference {{ticket_number}},
 * {{requester_name}}, {{subject}} and {{technician}}, substituted on insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            // Optional category association to keep relevant macros together.
            $table->string('category_key', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_responses');
    }
};
