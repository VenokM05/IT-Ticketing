<?php

namespace Database\Seeders;

use App\Enums\DeliveryStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Database\Seeder;

/**
 * Demo data for browsing the dashboard (PRD §10) across three companies. Safe to
 * re-run: logins use firstOrCreate; tickets are only generated when none exist.
 */
class DemoTicketSeeder extends Seeder
{
    public function run(): void
    {
        $companies = collect([
            ['Acme Manufacturing', 'ACME', 'it@acme.example'],
            ['Northwind Logistics', 'NWIND', 'helpdesk@northwind.example'],
            ['Globex Corporation', 'GLOBEX', 'support@globex.example'],
        ])->mapWithKeys(fn (array $row) => [$row[0] => Company::firstOrCreate(
            ['name' => $row[0]],
            ['code' => $row[1], 'email' => $row[2], 'location' => 'Cebu City', 'is_active' => true],
        )]);

        $admin = $this->user('Ada Admin', 'admin@example.com', UserRole::Admin);
        $tech1 = $this->user('Ben Technician', 'ben@example.com', UserRole::Technician, $companies['Acme Manufacturing']);
        $tech2 = $this->user('Cara Technician', 'cara@example.com', UserRole::Technician, $companies['Northwind Logistics']);

        // Departments belong to a company; employees inherit it through their department.
        $departments = collect([
            'IT Infrastructure' => 'Acme Manufacturing',
            'Finance' => 'Acme Manufacturing',
            'Helpdesk' => 'Northwind Logistics',
            'HR' => 'Northwind Logistics',
            'Operations' => 'Globex Corporation',
        ])->map(fn (string $companyName, string $deptName) => $this->department($deptName, $companies[$companyName]));

        $employees = collect([
            ['Dana Employee', '09171234501', 'Finance', 'Accountant'],
            ['Evan Employee', '09171234502', 'Operations', 'Warehouse Supervisor'],
            ['Faye Employee', '09171234503', 'HR', 'HR Officer'],
            ['Gus Employee', '09171234504', 'Finance', 'Finance Clerk'],
        ])->map(function (array $row) use ($departments) {
            $department = $departments->firstWhere('name', $row[2]);

            return Employee::firstOrCreate(
                ['whatsapp_number' => '+63'.substr($row[1], 2)],
                [
                    'name' => $row[0],
                    'email' => strtolower(str_replace(' ', '.', $row[0])).'-'.($row[1]).'@example.com',
                    'department_id' => $department->id,
                    'company_id' => $department->company_id,
                    'position' => $row[3],
                    'location' => 'HQ Floor '.rand(1, 5),
                    'is_active' => true,
                ],
            );
        });

        // Keep previously-seeded rows consistent with the new company tagging.
        Employee::query()->whereNull('company_id')->with('department:id,company_id')->each(function (Employee $employee) {
            if ($employee->department?->company_id !== null) {
                $employee->update(['company_id' => $employee->department->company_id]);
            }
        });

        if (Ticket::query()->count() > 0) {
            Ticket::query()->whereNull('company_id')->with('employee:id,company_id')->each(function (Ticket $ticket) {
                if ($ticket->employee?->company_id !== null) {
                    $ticket->update(['company_id' => $ticket->employee->company_id]);
                }
            });

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

        // Public, no-login web requests (process.md item 2): one whose typed company
        // matches a real one, and one that names an unknown company and so files
        // under the "Unassigned" bucket. Neither is tied to an enrolled employee.
        $public = function (string $name, string $claim, string $contact, string $subject, string $body) use ($service, $companies) {
            $needle = mb_strtolower($claim);
            $match = $companies->first(fn (Company $c) => mb_strtolower($c->name) === $needle);

            return $service->createTicket([
                'company_id' => $match?->id,
                'subject' => $subject,
                'description' => $body,
                'requester_name' => $name,
                'requester_contact' => $contact,
                'requester_company' => $match ? null : $claim,
            ]);
        };

        $public(
            'Grace Lim', 'Acme Manufacturing', 'grace.lim@example.test',
            'Visitor Wi-Fi password', 'Reception needs the guest Wi-Fi password for a client meeting.'
        );
        $public(
            'Sam Rivera', 'Wayne Enterprises', '639170000000',
            'Projector has no signal', 'Conference room projector shows no signal over HDMI.'
        );
    }

    private function user(string $name, string $email, UserRole $role, ?Company $company = null): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'role' => $role,
                'company_id' => $company?->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        if ($company !== null && $user->company_id === null) {
            $user->update(['company_id' => $company->id]);
        }

        return $user;
    }

    private function department(string $name, Company $company): Department
    {
        $department = Department::firstOrCreate(['name' => $name], ['company_id' => $company->id]);

        if ($department->company_id === null) {
            $department->update(['company_id' => $company->id]);
        }

        return $department;
    }
}
