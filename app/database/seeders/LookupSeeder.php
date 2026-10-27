<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Priority;
use Illuminate\Database\Seeder;

/**
 * Reference rows for the DB-managed classification lookups (PRD Phase 9). These
 * mirror the original TicketCategory / TicketPriority enums (and the config SLA
 * defaults) so a fresh --seed matches what the migration already inserted.
 * Idempotent: keyed on `key`, safe to re-run.
 */
class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['key' => 'hardware', 'label' => 'Hardware', 'sort' => 1],
            ['key' => 'software', 'label' => 'Software', 'sort' => 2],
            ['key' => 'network', 'label' => 'Network', 'sort' => 3],
            ['key' => 'printer', 'label' => 'Printer', 'sort' => 4],
            ['key' => 'access', 'label' => 'Access/Permissions', 'sort' => 5],
            ['key' => 'general', 'label' => 'General IT Support', 'sort' => 6],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(['key' => $category['key']], [
                'label' => $category['label'],
                'sort' => $category['sort'],
                'is_active' => true,
            ]);
        }

        $sla = config('tickets.sla', []);

        $priorities = [
            ['key' => 'critical', 'sort' => 1],
            ['key' => 'high', 'sort' => 2],
            ['key' => 'medium', 'sort' => 3],
            ['key' => 'low', 'sort' => 4],
        ];

        foreach ($priorities as $priority) {
            Priority::updateOrCreate(['key' => $priority['key']], [
                'label' => ucfirst($priority['key']),
                'sort' => $priority['sort'],
                'is_active' => true,
                'sla_response_hours' => $sla[$priority['key']]['response'] ?? null,
                'sla_resolve_hours' => $sla[$priority['key']]['resolve'] ?? null,
            ]);
        }
    }
}
