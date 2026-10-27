<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DB-managed classification lookups (PRD Phase 9, Rec #1). The hard-coded
     * TicketCategory / TicketPriority enums are promoted to editable tables so
     * administrators can add, rename, reorder and (for priorities) tune the SLA
     * hour budgets without a code change. The current enum values are inserted
     * here — not just in a seeder — so every migrated database (including the
     * in-memory test DB) has the reference rows available immediately.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->unsignedInteger('sla_response_hours')->nullable();
            $table->unsignedInteger('sla_resolve_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::table('categories')->insert([
            ['key' => 'hardware', 'label' => 'Hardware', 'is_active' => true, 'sort' => 1],
            ['key' => 'software', 'label' => 'Software', 'is_active' => true, 'sort' => 2],
            ['key' => 'network', 'label' => 'Network', 'is_active' => true, 'sort' => 3],
            ['key' => 'printer', 'label' => 'Printer', 'is_active' => true, 'sort' => 4],
            ['key' => 'access', 'label' => 'Access/Permissions', 'is_active' => true, 'sort' => 5],
            ['key' => 'general', 'label' => 'General IT Support', 'is_active' => true, 'sort' => 6],
        ]);

        DB::table('priorities')->insert([
            ['key' => 'critical', 'label' => 'Critical', 'sla_response_hours' => 1, 'sla_resolve_hours' => 4, 'is_active' => true, 'sort' => 1],
            ['key' => 'high', 'label' => 'High', 'sla_response_hours' => 4, 'sla_resolve_hours' => 8, 'is_active' => true, 'sort' => 2],
            ['key' => 'medium', 'label' => 'Medium', 'sla_response_hours' => 8, 'sla_resolve_hours' => 24, 'is_active' => true, 'sort' => 3],
            ['key' => 'low', 'label' => 'Low', 'sla_response_hours' => 24, 'sla_resolve_hours' => 72, 'is_active' => true, 'sort' => 4],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('priorities');
        Schema::dropIfExists('categories');
    }
};
