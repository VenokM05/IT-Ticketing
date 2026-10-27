<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companies (multi-tenant tagging). Companies identify which organization a
 * ticket request came from — on a shared database, visible to all IT staff
 * (tag-only, not an isolation boundary).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Short uppercase label used in lists and ticket-number context (e.g. ACME).
            $table->string('code', 16)->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
