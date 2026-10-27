<?php

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Support\Carbon;

// SLA deadlines are calendar-hour budgets per priority, stamped from creation and
// recomputed on a priority change. The state accessors classify each milestone.

afterEach(fn () => Carbon::setTestNow());

function slaCreate(TicketPriority $priority): Ticket
{
    return app(TicketService::class)->createTicket([
        'subject' => 'Needs triage',
        'description' => 'Deadline computation under test',
        'priority' => $priority,
        'category' => TicketCategory::General,
    ]);
}

it('stamps response and resolve deadlines from the critical window on create', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');

    $ticket = slaCreate(TicketPriority::Critical);

    // critical => response 1h, resolve 4h.
    expect($ticket->response_due_at->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and($ticket->resolve_due_at->toDateTimeString())->toBe('2026-11-01 13:00:00');
});

it('stamps the low priority window (24h / 72h)', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');

    $ticket = slaCreate(TicketPriority::Low);

    expect($ticket->response_due_at->toDateTimeString())->toBe('2026-11-02 09:00:00')
        ->and($ticket->resolve_due_at->toDateTimeString())->toBe('2026-11-04 09:00:00');
});

it('reports the response SLA as ok when a first response lands before the deadline', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');
    $ticket = slaCreate(TicketPriority::Critical); // due 10:00

    $ticket->first_response_at = Carbon::parse('2026-11-01 09:30:00');

    expect($ticket->slaResponseState())->toBe('ok');
});

it('reports a breach when the deadline passed with no response', function () {
    Carbon::setTestNow('2026-11-01 11:00:00');
    $ticket = slaCreate(TicketPriority::Critical); // created 11:00, due 12:00

    Carbon::setTestNow('2026-11-01 13:00:00');

    expect($ticket->slaResponseState())->toBe('breached');
});

it('flags at_risk inside the tail of an unmet window', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');
    $ticket = slaCreate(TicketPriority::Critical); // due 10:00 (1h window)

    // 50 of 60 minutes elapsed = 83% >= 75% threshold, still before the deadline.
    Carbon::setTestNow('2026-11-01 09:50:00');

    expect($ticket->slaResponseState())->toBe('at_risk')
        ->and($ticket->slaResolveState())->toBe('ok'); // resolve due 13:00, 50/240 = 21%
});

it('recomputes deadlines when the priority is changed', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');
    $service = app(TicketService::class);

    $ticket = slaCreate(TicketPriority::Low); // response due 2026-11-02 09:00
    expect($ticket->response_due_at->toDateTimeString())->toBe('2026-11-02 09:00:00');

    $service->updateClassification($ticket, Priority::resolve('critical'), Category::resolve('hardware'));

    // Re-derived from the same created_at with the critical window.
    expect($ticket->response_due_at->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and($ticket->fresh()->priority->key)->toBe('critical');
});

it('leaves deadlines null for a priority with no configured window', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');
    // SLA budgets are DB-managed now (Phase 9): blank the medium priority's hours.
    Priority::query()->where('key', 'medium')->update(['sla_response_hours' => null, 'sla_resolve_hours' => null]);

    $ticket = slaCreate(TicketPriority::Medium);

    expect($ticket->response_due_at)->toBeNull()
        ->and($ticket->resolve_due_at)->toBeNull()
        ->and($ticket->slaSummaryState())->toBeNull();
});
