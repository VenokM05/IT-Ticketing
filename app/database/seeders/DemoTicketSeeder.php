<?php

namespace Database\Seeders;

use App\Enums\DeliveryStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Database\Seeder;

/**
 * Demo data for browsing the Phase 4 dashboard (PRD §10). Safe to re-run:
 * logins use firstOrCreate; tickets are only generated when none exist.
 */
class DemoTicketSeeder extends Seeder
{
    public function run(): void
    {
        $admin = $this->user('Ada Admin', 'admin@example.com', UserRole::Admin);
        $tech1 = $this->user('Ben Technician', 'ben@example.com', UserRole::Technician);
        $tech2 = $this->user('Cara Technician', 'cara@example.com', UserRole::Technician);

        $departments = collect(['IT Infrastructure', 'Helpdesk', 'Finance', 'HR', 'Operations'])
            ->map(fn (string $name) => Department::firstOrCreate(['name' => $name]));

        $employees = collect([
            ['Dana Employee', '09171234501', 'Finance'],
            ['Evan Employee', '09171234502', 'Operations'],
            ['Faye Employee', '09171234503', 'HR'],
            ['Gus Employee', '09171234504', 'Finance'],
        ])->map(function (array $row) use ($departments) {
            return Employee::firstOrCreate(
                ['whatsapp_number' => '+63'.substr($row[1], 2)],
                [
                    'name' => $row[0],
                    'email' => strtolower(str_replace(' ', '.', $row[0])).'-'.($row[1]).'@example.com',
                    'department_id' => $departments->firstWhere('name', $row[2])->id,
                    'location' => 'HQ Floor '.rand(1, 5),
                    'is_active' => true,
                ],
            );
        });

        if (Ticket::query()->count() > 0) {
            return; // existing data set untouched
        }

        $service = app(TicketService::class);

        $make = function (string $subject, string $body, int $i) use ($service, $employees, $admin) {
            $ticket = $service->createTicket([
                'employee_id' => $employees[$i % $employees->count()]->id,
                'subject' => $subject,
                'description' => $body,
            ], $admin);

            TicketMessage::create([
                'ticket_id' => $ticket->id,
                'direction' => MessageDirection::Inbound,
                'channel' => MessageChannel::WhatsApp,
                'message_type' => MessageType::Text,
                'body' => $body,
                'delivery_status' => DeliveryStatus::Delivered,
                'whatsapp_message_id' => 'wamid.DEMO'.$ticket->id,
            ]);

            return $ticket;
        };

        // New — untouched.
        $make('Laptop won\'t power on', 'My laptop dead, meeting in an hour, please help!', 0);

        // Assigned.
        $t = $make('VPN access revoked after leave', 'I was on leave and now VPN says account disabled.', 1);
        $service->assignTechnician($t->fresh(), $tech1, $admin, 'Networking queue');

        // In Progress with a draft report (FR-10) and a progress update.
        $t = $make('Printer jam on 3rd floor', 'HP printer keeps jamming tray 2.', 2);
        $t = $service->assignTechnician($t->fresh(), $tech2, $admin);
        $t = $service->changeStatus($t, TicketStatus::InProgress, $tech2, 'Site visit scheduled');
        $t->progressUpdates()->create([
            'user_id' => $tech2->id,
            'work_performed' => 'Opened tray 2, removed crumpled sheets.',
            'findings' => 'Worn pickup roller.',
            'next_action' => 'Replace roller from stock.',
            'minutes_spent' => 35,
            'is_internal' => true,
        ]);

        // Resolved.
        $t = $make('Outlook keeps prompting for password', 'Outlook asks password every 5 minutes.', 3);
        $t = $service->assignTechnician($t->fresh(), $tech1, $admin);
        $t = $service->changeStatus($t, TicketStatus::InProgress, $tech1, 'Credential reset');
        $service->changeStatus($t, TicketStatus::Resolved, $tech1, 'Password reset + MFA re-enrolled');

        // Closed.
        $t = $make('Request: second monitor', 'Need a second monitor for payroll sheets.', 0);
        $t = $service->assignTechnician($t->fresh(), $tech2, $admin);
        $t = $service->changeStatus($t, TicketStatus::InProgress, $tech2, 'Issued from stock');
        $t = $service->changeStatus($t, TicketStatus::Resolved, $tech2, 'Monitor installed');
        $service->changeStatus($t, TicketStatus::Closed, $admin, 'Confirmed by employee');

        // Pending (waiting on user info).
        $make('Wi-Fi drops near pantry', 'Connection drops daily around 10am.', 1);
    }

    private function user(string $name, string $email, UserRole $role): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'role' => $role,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
