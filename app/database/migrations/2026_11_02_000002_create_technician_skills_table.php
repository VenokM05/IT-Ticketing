<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which request categories a technician is skilled in (matches TicketCategory values).
 * Powers skill-aware auto-assignment; a technician with no rows is treated as a
 * generalist who only receives tickets when no skilled option exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category_key', 40);
            $table->timestamps();

            $table->unique(['user_id', 'category_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_skills');
    }
};
