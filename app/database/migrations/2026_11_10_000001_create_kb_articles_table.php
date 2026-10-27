<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge base articles (PRD Phase 10, Rec #14). Short, self-service how-to
 * write-ups tied to a request category so a requester can be pointed at a fix
 * before (or alongside) opening a ticket. `category_key` mirrors the same stable
 * key used by technician skills and canned responses, so suggestions line up with
 * the intake classification without a hard foreign-key on a DB-managed lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('category_key', 40)->nullable();
            $table->boolean('published')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
    }
};
