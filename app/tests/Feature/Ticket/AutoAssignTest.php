<?php

use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;

// Auto-assignment at intake: a ticket with no explicit technician is routed to the
// company's least-loaded technician; every other case is left in the manual queue.

beforeEach(fn () => config(['tickets.auto_assign' => true]));

it('auto-assigns a new ticket to the company technician when none is chosen', function () {
    $company = Company::factory()->create();
    $tech = User::factory()->technician()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    $ticket = app(TicketService::class)->createTicket([
        'employee_id' => $employee->id,
        'subject' => 'VPN locked out',
        'description' => 'Cannot connect after return from leave.',
    ]);

    expect($ticket->assigned_to)->toBe($tech->id)
        ->and($ticket->status)->toBe(TicketStatus::Assigned);
});

it('leaves an explicitly chosen assignee untouched', function () {
    $company = Company::factory()->create();
    User::factory()->technician()->create(['company_id' => $company->id]); // would win by load
    $chosen = User::factory()->technician()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    $ticket = app(TicketService::class)->createTicket([
        'employee_id' => $employee->id,
        'subject' => 'Printer jam',
        'description' => 'Tray 2 keeps jamming.',
        'assigned_to' => $chosen->id,
        'status' => TicketStatus::Assigned,
    ]);

    expect($ticket->assigned_to)->toBe($chosen->id);
});

it('leaves the ticket New when the company has no technician', function () {
    $company = Company::factory()->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    $ticket = app(TicketService::class)->createTicket([
        'employee_id' => $employee->id,
        'subject' => 'Dead laptop',
        'description' => 'Will not power on.',
    ]);

    expect($ticket->assigned_to)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::New);
});

it('assigns the technician carrying the fewest open tickets', function () {
    $company = Company::factory()->create();
    $busy = User::factory()->technician()->create(['company_id' => $company->id]);
    $free = User::factory()->technician()->create(['company_id' => $company->id]);

    // Seed the workload directly (bypassing the service so it does not recurse).
    Ticket::factory()->count(2)->create([
        'company_id' => $company->id, 'assigned_to' => $busy->id, 'status' => TicketStatus::InProgress,
    ]);
    Ticket::factory()->count(1)->create([
        'company_id' => $company->id, 'assigned_to' => $free->id, 'status' => TicketStatus::InProgress,
    ]);

    $ticket = app(TicketService::class)->createTicket([
        'company_id' => $company->id,
        'subject' => 'Shared drive mapping',
        'description' => 'Z: drive disappeared.',
    ]);

    expect($ticket->assigned_to)->toBe($free->id);
});

it('does not auto-assign when the feature is disabled', function () {
    config(['tickets.auto_assign' => false]);

    $company = Company::factory()->create();
    User::factory()->technician()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    $ticket = app(TicketService::class)->createTicket([
        'employee_id' => $employee->id,
        'subject' => 'Outlook password prompt',
        'description' => 'Prompts for password every few minutes.',
    ]);

    expect($ticket->assigned_to)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::New);
});

it('prefers a category-skilled technician over a less-loaded generalist', function () {
    $company = Company::factory()->create();
    $generalist = User::factory()->technician()->create(['company_id' => $company->id]);
    $specialist = User::factory()->technician()->create(['company_id' => $company->id]);
    $specialist->skills()->create(['category_key' => TicketCategory::Printer->value]);

    // Load the specialist heavier so only the skill preference can override it.
    Ticket::factory()->count(3)->create([
        'company_id' => $company->id, 'assigned_to' => $specialist->id, 'status' => TicketStatus::InProgress,
    ]);

    $ticket = app(TicketService::class)->createTicket([
        'company_id' => $company->id,
        'subject' => 'Toner refill',
        'description' => 'Floor 3 printer out of toner.',
        'category' => TicketCategory::Printer,
    ]);

    expect($ticket->assigned_to)->toBe($specialist->id)
        ->and($ticket->assigned_to)->not->toBe($generalist->id);
});

it('prefers the on-call skilled technician when several are skilled', function () {
    $company = Company::factory()->create();
    $available = User::factory()->technician()->create(['company_id' => $company->id]);
    $available->skills()->create(['category_key' => TicketCategory::Network->value]);

    $onCall = User::factory()->technician()->create(['company_id' => $company->id, 'is_on_call' => true]);
    $onCall->skills()->create(['category_key' => TicketCategory::Network->value]);

    // Give the non-on-call tech fewer open tickets; on-call should still win.
    Ticket::factory()->count(2)->create([
        'company_id' => $company->id, 'assigned_to' => $onCall->id, 'status' => TicketStatus::InProgress,
    ]);

    $ticket = app(TicketService::class)->createTicket([
        'company_id' => $company->id,
        'subject' => 'Switch port dead',
        'description' => 'No link on office switch.',
        'category' => TicketCategory::Network,
    ]);

    expect($ticket->assigned_to)->toBe($onCall->id);
});

it('falls back to least-loaded overall when nobody has the skill', function () {
    $company = Company::factory()->create();
    $busy = User::factory()->technician()->create(['company_id' => $company->id]);
    $busy->skills()->create(['category_key' => TicketCategory::Hardware->value]);
    $free = User::factory()->technician()->create(['company_id' => $company->id]);

    Ticket::factory()->count(2)->create([
        'company_id' => $company->id, 'assigned_to' => $free->id, 'status' => TicketStatus::InProgress,
    ]);

    // Category has no matching skill anywhere -> general load balancing (busier tech has a skill but it is irrelevant here; fewest open wins).
    $ticket = app(TicketService::class)->createTicket([
        'company_id' => $company->id,
        'subject' => 'Badge reader',
        'description' => 'Door badge reader offline.',
        'category' => TicketCategory::Access,
    ]);

    // busy has 0 open, free has 2 -> busy is least loaded.
    expect($ticket->assigned_to)->toBe($busy->id);
});
