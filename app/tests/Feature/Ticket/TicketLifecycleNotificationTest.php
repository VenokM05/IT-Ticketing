<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\ReportStatus;
use App\Enums\TicketStatus;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketProgressReport;
use App\Models\TicketProgressUpdate;
use App\Models\User;
use App\Services\TicketService;
use App\Services\WhatsAppInboundService;
use Livewire\Volt\Volt;

beforeEach(function () {
    config(['services.whatsapp.enabled' => true]);
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
});

/** A recent inbound message opens the 24h session window so routing sends a session message. */
function openWindow(Ticket $ticket): void
{
    TicketMessage::create([
        'ticket_id' => $ticket->id,
        'direction' => MessageDirection::Inbound,
        'message_type' => 'text',
        'body' => 'original issue text',
    ]);
}

function lifecycleEmployee(): Employee
{
    return Employee::factory()->create(['whatsapp_number' => '639171234567', 'is_active' => true]);
}

it('sends the employee a WhatsApp notification when a technician is assigned (FR-06, §11)', function () {
    $employee = lifecycleEmployee();
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);
    openWindow($ticket);

    app(TicketService::class)->assignTechnician($ticket, User::factory()->technician()->create(), User::factory()->admin()->create());

    $outbound = TicketMessage::where('ticket_id', $ticket->id)->where('direction', MessageDirection::Outbound)->get();
    expect($outbound->count())->toBe(1)
        ->and($outbound->first()->whatsapp_message_id)->toBe('wamid.OUT')
        ->and($outbound->first()->delivery_status)->toBe(DeliveryStatus::Sent);
    Http::assertSent(fn ($request) => $request['type'] === 'text');
});

it('sends the employee a WhatsApp notification on a status transition', function () {
    $employee = lifecycleEmployee();
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);
    openWindow($ticket);

    app(TicketService::class)->changeStatus($ticket, TicketStatus::InProgress, User::factory()->admin()->create());

    expect(TicketMessage::where('ticket_id', $ticket->id)
        ->where('direction', MessageDirection::Outbound)
        ->where('body', 'like', '%Work has started%')->count())->toBe(1);
});

it('dispatches nothing over WhatsApp when messaging is disabled', function () {
    config(['services.whatsapp.enabled' => false]);

    $employee = lifecycleEmployee();
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);
    openWindow($ticket);

    app(TicketService::class)->changeStatus($ticket, TicketStatus::InProgress, User::factory()->admin()->create());

    expect(TicketMessage::where('ticket_id', $ticket->id)->where('direction', MessageDirection::Outbound)->count())->toBe(0);
    Http::assertSentCount(0);
});

it('records a progress entry and finalizes the draft initial report on the first entry (FR-10)', function () {
    $service = app(TicketService::class);
    $employee = lifecycleEmployee();
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);
    $tech = User::factory()->technician()->create();
    $ticket = $service->assignTechnician($ticket, $tech);
    $ticket = $service->changeStatus($ticket, TicketStatus::InProgress, $tech, null, false);

    $draft = TicketProgressReport::where('ticket_id', $ticket->id)->sole();
    expect($draft->status)->toBe(ReportStatus::Draft);

    $service->addProgress($ticket, $tech, [
        'work_performed' => 'Replaced toner cartridge.',
        'findings' => 'Cartridge was empty.',
        'next_action' => 'Monitor supply levels.',
        'minutes_spent' => 35,
    ]);

    $update = TicketProgressUpdate::where('ticket_id', $ticket->id)->sole();
    expect($update->minutes_spent)->toBe(35)->and($update->is_internal)->toBeFalse();
    expect($draft->fresh()->status)->toBe(ReportStatus::Final)
        ->and($draft->fresh()->work_performed)->toBe('Replaced toner cartridge.')
        ->and($draft->fresh()->finalized_at)->not->toBeNull();
});

it('adds a progress entry through the ticket page and blocks an empty submission', function () {
    $employee = lifecycleEmployee();
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id, 'status' => TicketStatus::InProgress]);
    $tech = User::factory()->technician()->create();
    $ticket->update(['assigned_to' => $tech->id]);

    $this->actingAs($tech);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->call('addProgress')
        ->assertHasErrors('progressWork');

    expect(TicketProgressUpdate::count())->toBe(0);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->set('progressWork', 'Cleared print queue.')
        ->set('progressFindings', 'Stuck job.')
        ->call('addProgress');

    expect(TicketProgressUpdate::where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('stores an internal note without sending it, even when WhatsApp is enabled', function () {
    $employee = lifecycleEmployee();
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->set('noteBody', 'Internal: awaiting vendor part.')
        ->set('noteInternal', true)
        ->call('addNote');

    $note = TicketMessage::where('ticket_id', $ticket->id)->where('direction', MessageDirection::Outbound)->sole();
    expect($note->is_internal)->toBeTrue()->and($note->whatsapp_message_id)->toBeNull();
    Http::assertSentCount(0);
});

it('sends an employee-visible note over WhatsApp when messaging is enabled', function () {
    $employee = lifecycleEmployee();
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);
    openWindow($ticket);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->set('noteBody', 'Please restart your machine.')
        ->set('noteInternal', false)
        ->call('addNote');

    $note = TicketMessage::where('ticket_id', $ticket->id)->where('direction', MessageDirection::Outbound)->sole();
    expect($note->is_internal)->toBeFalse()
        ->and($note->whatsapp_message_id)->toBe('wamid.OUT')
        ->and($note->delivery_status)->toBe(DeliveryStatus::Sent);
});

it('does not double-message a WhatsApp CLOSE command (inline reply only)', function () {
    $employee = lifecycleEmployee();
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id, 'status' => TicketStatus::InProgress]);

    app(WhatsAppInboundService::class)->handle([
        'from' => '639171234567',
        'message_id' => 'wamid.CLOSE1',
        'type' => 'text',
        'body' => 'CLOSE '.$ticket->ticket_number,
        'context_id' => null,
        'timestamp' => now()->timestamp,
    ]);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed)
        ->and(TicketMessage::where('ticket_id', $ticket->id)->where('direction', MessageDirection::Outbound)->count())->toBe(1);
});
