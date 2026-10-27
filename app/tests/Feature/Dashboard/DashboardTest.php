<?php

use App\Enums\NotificationType;
use App\Models\Company;
use App\Models\DashboardNotification;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Livewire\Volt\Volt;

function dashAdmin(): User
{
    return User::factory()->admin()->create();
}

function dashTech(): User
{
    return User::factory()->technician()->create();
}

it('redirects guests from dashboard, tickets and notifications', function (string $route) {
    $this->get($route)->assertRedirect(route('login'));
})->with(['/dashboard', '/tickets', '/notifications']);

it('shows status counters and the latest ticket to an admin', function () {
    $admin = dashAdmin();
    Ticket::factory()->create(['subject' => 'Laptop dead', 'assigned_to' => null]);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Laptop dead');
});

it('shows the public requester snapshot name in the latest list (no enrolled employee)', function () {
    $admin = dashAdmin();
    Ticket::factory()->create([
        'employee_id' => null,
        'requester_name' => 'External Visitor',
        'subject' => 'Website down',
    ]);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('External Visitor');
});

it('shows the requester company and department columns in the latest list', function () {
    $admin = dashAdmin();
    $company = Company::factory()->create(['name' => 'Acme Manufacturing', 'code' => null]);
    $employee = Employee::factory()->create([
        'company_id' => $company,
        'department_id' => Department::factory()->create(['name' => 'Finance']),
    ]);
    // Direct factory creation skips the service's company inheritance, so stamp it.
    Ticket::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'subject' => 'Scanner offline',
    ]);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Acme Manufacturing')
        ->assertSee('Finance');
});

it('scopes dashboard counters and latest list to the technician queue (§3.3)', function () {
    $tech = dashTech();
    Ticket::factory()->create(['assigned_to' => $tech->id, 'subject' => 'Mine printer jam']);
    Ticket::factory()->create(['subject' => 'Someone elses request']);

    $this->actingAs($tech);
    Volt::test('dashboard')
        ->assertSee('Mine printer jam')
        ->assertDontSee('Someone elses request');

    // The admin sees everything.
    $this->actingAs(dashAdmin());
    Volt::test('dashboard')
        ->assertSee('Someone elses request');
});

it('counts unread notifications per user on the dashboard (FR-05)', function () {
    $admin = dashAdmin();
    $ticket = Ticket::factory()->create();
    DashboardNotification::create([
        'user_id' => $admin->id,
        'ticket_id' => $ticket->id,
        'type' => NotificationType::NewTicket->value,
        'title' => 'New ticket '.$ticket->ticket_number,
    ]);

    expect(DashboardNotification::query()
        ->where('user_id', $admin->id)
        ->whereNull('read_at')
        ->count())->toBe(1);

    $this->actingAs($admin)->get('/dashboard')->assertSee('Unread');
});

it('notifies admins on create and the technician on assignment through the service', function () {
    $admin = dashAdmin();
    $tech = dashTech();

    // Creation and assignment both routed through TicketService so events fire.
    $ticket = app(TicketService::class)->createTicket([
        'employee_id' => Employee::factory()->create()->id,
        'subject' => 'VPN locked out',
        'description' => 'Cannot connect since this morning.',
    ], $admin);

    app(TicketService::class)->assignTechnician($ticket, $tech, $admin);

    // Admin got the new-ticket notice; tech got the assignment notice.
    expect(DashboardNotification::query()->where('user_id', $admin->id)
        ->where('type', NotificationType::NewTicket->value)->count())->toBe(1)
        ->and(DashboardNotification::query()->where('user_id', $tech->id)
            ->where('type', NotificationType::TicketAssigned->value)
            ->count())->toBe(1);
});
