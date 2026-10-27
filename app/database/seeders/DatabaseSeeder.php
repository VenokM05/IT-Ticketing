<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Single IT Administrator login for the dashboard admin pages.
        $this->call(AdminUserSeeder::class);

        // DB-managed classification lookups (categories + priorities, Phase 9).
        $this->call(LookupSeeder::class);

        // Live client companies + one IT technician account each (auto-assign target).
        $this->call(CompanyProvisionerSeeder::class);

        // §11.3 outbound template inventory.
        $this->call(WhatsAppTemplateSeeder::class);
    }
}
