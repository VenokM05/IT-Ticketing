<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            // Meta-approved template name (unique with language) — PRD §11.3.
            $table->string('name');
            $table->string('language')->default('en');
            $table->string('category')->default('utility');
            // Approval state from Meta: pending | approved | rejected.
            $table->string('approval_status')->default('pending');
            $table->text('body')->nullable();
            // Which outbound notification event this template maps to.
            $table->string('notification_type')->nullable();
            $table->timestamps();

            $table->unique(['name', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
