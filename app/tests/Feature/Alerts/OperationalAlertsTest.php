<?php

use App\Enums\NotificationType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\DashboardNotification;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

/*
 * Scheduled operational alerts (process.md items 5 and 6): the daily pending-request
 * reminder and the Friday weekly monitoring summary. Both land in every active
 * administrator's notification feed.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->tech = User::factory()->technician()->create();
    $this->acme = Company::factory()->create(['name' => 'Acme Manufacturing']);
    $this->department = Department::factory()->create();
});

function alertTicket(Company $company, Department $department, TicketStatus $status, User $assignee): Ticket
{
    $employee = Employee::factory()->create([
        'department_id' => $department->id,
        'company_id' => $company->id,
    ]);

    return Ticket::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'assigned_to' => $assignee->id,
        'status' => $status,
        'priority' => TicketPriority::Medium,
    ]);
}

it('alerts administrators about requests still awaiting action, grouped by company', function () {
    alertTicket($this->acme, $this->department, TicketStatus::New, $this->tech);
    alertTicket($this->acme, $this->department, TicketStatus::InProgress, $this->tech);
    alertTicket($this->acme, $this->department, TicketStatus::Resolved, $this->tech);

    $this->artisan('tickets:pending-alert')->assertSuccessful();

    $alert = DashboardNotification::query()
        ->where('user_id', $this->admin->id)
        ->where('type', NotificationType::PendingTicketsAlert->value)
        ->sole();

    expect($alert->title)->toBe('Pending ticket requests: 2')
        ->and($alert->data['by_company'])->toBe(['Acme Manufacturing' => 2])
        ->and($alert->message)->toContain('Acme Manufacturing: 2');

    // Technicians are not the audience for management alerts.
    expect(DashboardNotification::query()->where('user_id', $this->tech->id)->count())->toBe(0);
});

it('sends nothing when the queue is clear', function () {
    alertTicket($this->acme, $this->department, TicketStatus::Closed, $this->tech);

    $this->artisan('tickets:pending-alert')->assertSuccessful();

    expect(DashboardNotification::query()
        ->where('type', NotificationType::PendingTicketsAlert->value)
        ->count())->toBe(0);
});

it('alerts once per day even if the scheduler reruns', function () {
    alertTicket($this->acme, $this->department, TicketStatus::New, $this->tech);

    $this->artisan('tickets:pending-alert');
    $this->artisan('tickets:pending-alert');

    expect(DashboardNotification::query()
        ->where('type', NotificationType::PendingTicketsAlert->value)
        ->count())->toBe(1);
});

it('publishes the weekly summary with totals and company breakdown', function () {
    $today = now()->toDateString();

    $open = alertTicket($this->acme, $this->department, TicketStatus::InProgress, $this->tech);
    $resolved = alertTicket($this->acme, $this->department, TicketStatus::Resolved, $this->tech);
    $resolved->forceFill(['resolved_at' => now()])->save();

    // Outside the reporting window: older than the requested range.
    $old = alertTicket($this->acme, $this->department, TicketStatus::Closed, $this->tech);
    $old->forceFill(['created_at' => now()->subMonths(3)])->save();

    $this->artisan('tickets:weekly-summary', ['--from' => $today, '--to' => $today])
        ->assertSuccessful();

    $summary = DashboardNotification::query()
        ->where('user_id', $this->admin->id)
        ->where('type', NotificationType::WeeklySummary->value)
        ->sole();

    expect($summary->title)->toContain($today)
        ->and($summary->message)->toContain('Created 2')
        ->and($summary->message)->toContain('Resolved 1')
        ->and($summary->data['by_company'][0]['company'])->toBe('Acme Manufacturing')
        ->and($summary->data['by_company'][0]['count'])->toBe(2);
});

it('reuses the weekly totals for the same day rather than duplicating', function () {
    $today = now()->toDateString();
    alertTicket($this->acme, $this->department, TicketStatus::New, $this->tech);

    $this->artisan('tickets:weekly-summary', ['--from' => $today, '--to' => $today]);
    $this->artisan('tickets:weekly-summary', ['--from' => $today, '--to' => $today]);

    expect(DashboardNotification::query()
        ->where('type', NotificationType::WeeklySummary->value)
        ->count())->toBe(1);
});

it('schedules the 5 PM pending alert and the Friday summary on the team clock', function () {
    $events = collect(app(Schedule::class)->events());

    $pending = $events->first(fn (object $e) => str_contains((string) ($e->command ?? ''), 'tickets:pending-alert'));
    $weekly = $events->first(fn (object $e) => str_contains((string) ($e->command ?? ''), 'tickets:weekly-summary'));

    expect($pending)->not->toBeNull()
        ->and($pending->expression)->toBe('0 17 * * *')
        ->and($pending->timezone)->toBe(config('alerts.timezone'))
        ->and($weekly)->not->toBeNull()
        ->and($weekly->expression)->toBe('0 17 * * 5');
});
