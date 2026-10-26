<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Stored normalized in E.164 (PRD §9.1). Uniqueness among active records is
            // enforced in application validation + a filtered unique index on MySQL.
            $table->string('whatsapp_number');
            $table->string('email')->nullable();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('whatsapp_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
