<?php

use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use App\Services\WhatsAppInboundService;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

// CSAT capture (Phase 6, Rec #7): a 1-5 satisfaction score with an optional comment,
// stamped at resolve/close from the ticket page or a WhatsApp CLOSE command.

it('records a clamped satisfaction score with a comment', function () {
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Resolved]);

    app(TicketService::class)->recordCsat($ticket, 7, 'Superb, fixed in minutes');

    expect($ticket->fresh())
        ->rating->toBe(5) // clamped into the 1-5 band
        ->rating_comment->toBe('Superb, fixed in minutes')
        ->rated_at->not->toBeNull();
});

it('treats an out-of-range low score as the floor and blank comment as null', function () {
    $ticket = Ticket::factory()->create();

    app(TicketService::class)->recordCsat($ticket, 0, '   ');

    expect($ticket->fresh())->rating->toBe(1)->rating_comment->toBeNull();
});

it('captures CSAT when a technician closes with a score on the ticket page', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $tech->id, 'status' => TicketStatus::Resolved]);

    $this->actingAs($tech);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->set('csatRating', '4')
        ->set('csatComment', 'Quick and friendly')
        ->call('changeStatus', TicketStatus::Closed->value);

    $fresh = $ticket->fresh();
    expect($fresh->status)->toBe(TicketStatus::Closed)
        ->and($fresh->rating)->toBe(4)
        ->and($fresh->rating_comment)->toBe('Quick and friendly');
});

it('does not stamp CSAT when no score is given on close', function () {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

    $this->actingAs($admin);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->call('changeStatus', TicketStatus::Closed->value);

    expect($ticket->fresh()->rating)->toBeNull();
});

it('captures CSAT from a WhatsApp CLOSE command', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Department::factory()->create();

    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);
    $ticket = Ticket::factory()->create([
        'employee_id' => $employee->id,
        'ticket_number' => 'IT-2026-000555',
        'status' => TicketStatus::InProgress,
    ]);

    app(WhatsAppInboundService::class)->handle([
        'from' => '639171234567',
        'message_id' => 'wamid.'.uniqid(),
        'type' => 'text',
        'body' => 'CLOSE IT-2026-000555 5 Fixed it fast, thank you',
        'context_id' => null,
        'timestamp' => now()->timestamp,
    ]);

    $fresh = $ticket->fresh();
    expect($fresh->status)->toBe(TicketStatus::Closed)
        ->and($fresh->rating)->toBe(5)
        ->and($fresh->rating_comment)->toBe('Fixed it fast, thank you');
});

it('leaves CSAT null for a bare WhatsApp CLOSE command', function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Department::factory()->create();

    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);
    $ticket = Ticket::factory()->create([
        'employee_id' => $employee->id,
        'ticket_number' => 'IT-2026-000556',
        'status' => TicketStatus::InProgress,
    ]);

    app(WhatsAppInboundService::class)->handle([
        'from' => '639171234567',
        'message_id' => 'wamid.'.uniqid(),
        'type' => 'text',
        'body' => 'CLOSE IT-2026-000556',
        'context_id' => null,
        'timestamp' => now()->timestamp,
    ]);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->fresh()->rating)->toBeNull();
});
