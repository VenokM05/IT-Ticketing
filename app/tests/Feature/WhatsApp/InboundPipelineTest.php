<?php

use App\Enums\MessageDirection;
use App\Enums\NotificationType;
use App\Enums\TicketStatus;
use App\Models\DashboardNotification;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RelinkRequest;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\WhatsAppInboundService;

beforeEach(function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
    Department::factory()->create();
});

function inbound(array $message): void
{
    app(WhatsAppInboundService::class)->handle($message);
}

function message(string $from, string $body, string $type = 'text', ?string $contextId = null): array
{
    return ['from' => $from, 'message_id' => 'wamid.'.uniqid(), 'type' => $type, 'body' => $body, 'context_id' => $contextId, 'timestamp' => now()->timestamp];
}

it('sends unknown-sender guidance exactly once per 24h and creates no ticket', function () {
    inbound(message('639000000001', 'help me please with excel'));
    inbound(message('639000000001', 'still need help here'));

    expect(Ticket::count())->toBe(0);
    Http::assertSentCount(1);
});

it('creates a ticket and an acknowledgment for a valid new request', function () {
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    inbound(message('639171234567', 'reporting software crashes on startup'));

    expect(Ticket::count())->toBe(1);
    expect(TicketMessage::where('direction', MessageDirection::Outbound)->count())->toBe(1);
});

it('honours the explicit NEW TICKET command', function () {
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    inbound(message('639171234567', 'NEW TICKET request access to shared drive'));

    expect(Ticket::count())->toBe(1);
    expect(Ticket::first()->subject)->toContain('request access to shared drive');
});

it('does not open a ticket for a pure greeting', function () {
    Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);

    inbound(message('639171234567', 'thanks'));

    expect(Ticket::count())->toBe(0);
});

it('attaches an explicit ticket reference to the sender\'s own ticket', function () {
    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id, 'status' => TicketStatus::InProgress]);

    inbound(message('639171234567', $ticket->ticket_number.' also happens on the laptop'));

    expect(Ticket::count())->toBe(1); // no duplicate
    expect(TicketMessage::where('ticket_id', $ticket->id)->where('direction', MessageDirection::Inbound)->count())->toBe(1);
});

it('auto-attaches to a single open ticket within the follow-up window', function () {
    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id, 'status' => TicketStatus::New]);

    // Seed a recent inbound on that ticket so the window rule can match.
    TicketMessage::create([
        'ticket_id' => $ticket->id,
        'direction' => MessageDirection::Inbound,
        'message_type' => 'text',
        'body' => 'original issue text',
    ]);

    inbound(message('639171234567', 'it happened again just now'));

    expect(Ticket::count())->toBe(1);
    expect(TicketMessage::where('ticket_id', $ticket->id)->where('direction', MessageDirection::Inbound)->count())->toBe(2);
});

it('replies with the status for a STATUS command', function () {
    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id, 'status' => TicketStatus::InProgress]);

    inbound(message('639171234567', 'STATUS '.$ticket->ticket_number));

    expect(Ticket::count())->toBe(1); // STATUS never creates
    expect(TicketMessage::where('ticket_id', $ticket->id)->where('direction', MessageDirection::Outbound)->count())->toBe(1);
});

it('closes a ticket for a CLOSE command', function () {
    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id, 'status' => TicketStatus::InProgress]);

    inbound(message('639171234567', 'CLOSE '.$ticket->ticket_number));

    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed);
});

it('threads a reply back to the ticket its outbound message belongs to', function () {
    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id, 'status' => TicketStatus::Pending]);

    $outbound = TicketMessage::create([
        'ticket_id' => $ticket->id,
        'direction' => MessageDirection::Outbound,
        'message_type' => 'text',
        'body' => 'Pending info',
        'whatsapp_message_id' => 'wamid.THREAD',
    ]);

    inbound(message('639171234567', 'here is the info you asked for', 'text', $outbound->whatsapp_message_id));

    expect(Ticket::count())->toBe(1);
    expect(TicketMessage::where('ticket_id', $ticket->id)->where('direction', MessageDirection::Inbound)->count())->toBe(1);
});

it('raises a relink request when an unknown number sends REGISTER', function () {
    User::factory()->admin()->create();

    inbound(message('639999999999', 'REGISTER'));

    expect(RelinkRequest::count())->toBe(1);
    expect(RelinkRequest::first()->status)->toBe(RelinkRequest::STATUS_PENDING);
    expect(DashboardNotification::where('type', NotificationType::RelinkRequest)->count())->toBeGreaterThan(0);
});
