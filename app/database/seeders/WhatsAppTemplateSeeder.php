<?php

namespace Database\Seeders;

use App\Models\WhatsAppTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the §11.3 approved-template inventory into the whatsapp_templates config
 * table. Approval status mirrors a Meta submission tracker; in a fresh demo the
 * two Phase-3 templates are marked approved so the outbound path is exercisable,
 * the rest start pending (PRD Phase 0 exit criteria).
 */
class WhatsAppTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['ticket_acknowledgment', 'approved', 'Ticket {{1}} received: {{2}}'],
            ['unregistered_guidance', 'approved', 'This number is not registered. Contact IT to enroll.'],
            ['ticket_assigned', 'pending', 'Ticket {{1}} assigned to {{2}}'],
            ['ticket_in_progress', 'pending', 'Work started on ticket {{1}}'],
            ['ticket_pending', 'pending', 'Ticket {{1}} is pending more info'],
            ['ticket_resolved', 'pending', 'Ticket {{1}} resolved'],
            ['ticket_closed', 'pending', 'Ticket {{1}} closed'],
            ['ticket_status_reply', 'pending', 'Ticket {{1}} is {{2}}'],
        ];

        foreach ($templates as [$name, $status, $body]) {
            WhatsAppTemplate::query()->firstOrCreate(
                ['name' => $name, 'language' => 'en'],
                [
                    'category' => 'utility',
                    'approval_status' => $status,
                    'body' => $body,
                ],
            );
        }
    }
}
