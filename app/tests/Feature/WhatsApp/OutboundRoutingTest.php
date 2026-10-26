<?php

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppOutboundService;

beforeEach(function () {
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);
});

function outboundService(): WhatsAppOutboundService
{
    return app(WhatsAppOutboundService::class);
}

it('sends a free-form session message when the window is open', function () {
    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567']);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);

    // A recent inbound opens the 24h window (§11.1).
    TicketMessage::create([
        'ticket_id' => $ticket->id,
        'direction' => MessageDirection::Inbound,
        'message_type' => 'text',
        'body' => 'issue text here',
    ]);

    $message = outboundService()->notifyTicket($ticket, $employee, 'Ticket acknowledged', 'ticket_acknowledgment', ['X']);

    expect($message)->not->toBeNull()
        ->and($message->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($message->whatsapp_message_id)->toBe('wamid.OUT');
});

it('records a failed_window_constraint when out of window with no approved template', function () {
    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567']);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);
    // No inbound messages → window is closed.

    $message = outboundService()->notifyTicket($ticket, $employee, 'Ticket resolved', 'ticket_resolved', ['X']);

    expect($message->delivery_status)->toBe(DeliveryStatus::Failed);
    expect($message->metadata)->toHaveKey('reason', 'failed_window_constraint');
});

it('falls back to an approved template when out of window', function () {
    WhatsAppTemplate::create(['name' => 'ticket_resolved', 'language' => 'en', 'approval_status' => 'approved']);
    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567']);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);

    $message = outboundService()->notifyTicket($ticket, $employee, 'Ticket resolved', 'ticket_resolved', ['TICKET-1']);

    expect($message->delivery_status)->toBe(DeliveryStatus::Sent);
    Http::assertSent(fn ($request) => $request['type'] === 'template');
});

it('reconciles a delivery-status receipt onto the stored outbound message', function () {
    $employee = Employee::factory()->create(['whatsapp_number' => '639171234567']);
    $ticket = Ticket::factory()->create(['employee_id' => $employee->id]);

    $out = TicketMessage::create([
        'ticket_id' => $ticket->id,
        'direction' => MessageDirection::Outbound,
        'message_type' => 'text',
        'body' => 'hello',
        'whatsapp_message_id' => 'wamid.REC1',
        'delivery_status' => DeliveryStatus::Sent,
    ]);

    outboundService()->reconcileDelivery('wamid.REC1', 'delivered');

    expect($out->fresh()->delivery_status)->toBe(DeliveryStatus::Delivered);
});
