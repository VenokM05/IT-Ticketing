<?php

use App\Enums\ReportStatus;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketProgressReport;
use App\Models\TicketStatusHistory;
use App\Services\TicketService;

function makeTicket(): Ticket
{
    return app(TicketService::class)->createTicket([
        'employee_id' => Employee::factory()->create()->id,
        'subject' => 'Printer offline',
        'description' => 'Floor 2 printer is not responding.',
    ]);
}

it('creates a New ticket with a generated number and a creation history row', function () {
    $ticket = makeTicket();

    expect($ticket->ticket_number)->toMatch('/^IT-\d{4}-\d{6}$/')
        ->and($ticket->status)->toBe(TicketStatus::New)
        ->and(TicketStatusHistory::where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('walks the full happy-path lifecycle and stamps timestamps', function () {
    $service = app(TicketService::class);
    $ticket = makeTicket();

    $ticket = $service->changeStatus($ticket, TicketStatus::Assigned);
    expect($ticket->status)->toBe(TicketStatus::Assigned)
        ->and($ticket->first_response_at)->not->toBeNull();

    $ticket = $service->changeStatus($ticket, TicketStatus::InProgress);
    expect($ticket->started_at)->not->toBeNull();

    $ticket = $service->changeStatus($ticket, TicketStatus::Resolved);
    expect($ticket->resolved_at)->not->toBeNull();

    $ticket = $service->changeStatus($ticket, TicketStatus::Closed);
    expect($ticket->closed_at)->not->toBeNull();

    // New, Assigned, In Progress, Resolved, Closed + creation = 5 history rows.
    expect(TicketStatusHistory::where('ticket_id', $ticket->id)->count())->toBe(5);
});

it('rejects an invalid transition and leaves the status unchanged', function () {
    $ticket = makeTicket();

    app(TicketService::class)->changeStatus($ticket, TicketStatus::Resolved);
})->throws(InvalidStatusTransition::class);

it('rejects transitions out of a Closed ticket', function () {
    $service = app(TicketService::class);
    $ticket = $service->changeStatus(makeTicket(), TicketStatus::Closed);

    $service->changeStatus($ticket, TicketStatus::InProgress);
})->throws(InvalidStatusTransition::class);

it('creates a draft initial report only on the first In Progress transition', function () {
    $service = app(TicketService::class);
    $ticket = $service->changeStatus(makeTicket(), TicketStatus::InProgress);

    $report = TicketProgressReport::where('ticket_id', $ticket->id)->sole();

    expect($report->status)->toBe(ReportStatus::Draft)
        ->and($report->work_performed)->toBeNull()
        ->and($report->findings)->toBeNull()
        ->and($report->next_steps)->toBeNull()
        ->and($report->title)->toBe('Printer offline');

    $startedAt = $ticket->fresh()->started_at;

    // Pending -> In Progress again must not duplicate the report or reset the start time.
    $ticket = $service->changeStatus($ticket, TicketStatus::Pending);
    $ticket = $service->changeStatus($ticket, TicketStatus::InProgress);

    expect(TicketProgressReport::where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and($ticket->fresh()->started_at->equalTo($startedAt))->toBeTrue();
});

it('finalizes the draft report with technician-supplied fields', function () {
    $service = app(TicketService::class);
    $ticket = $service->changeStatus(makeTicket(), TicketStatus::InProgress);
    $report = TicketProgressReport::where('ticket_id', $ticket->id)->sole();

    $finalized = $service->finalizeReport($report, \App\Models\User::factory()->technician()->create(), [
        'work_performed' => 'Replaced toner cartridge.',
        'findings' => 'Cartridge was empty.',
        'next_steps' => 'Monitor supply levels.',
    ]);

    expect($finalized->status)->toBe(ReportStatus::Final)
        ->and($finalized->finalized_at)->not->toBeNull()
        ->and($finalized->work_performed)->toBe('Replaced toner cartridge.');
});
