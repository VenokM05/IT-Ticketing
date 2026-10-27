<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Carbon;

/**
 * Outbound send routing per the §11.2 decision rule: within an active session
 * window send a free-form session message; otherwise fall back to an approved
 * template; if neither is possible record a failed_window_constraint intent.
 *
 * Every ticketed send is persisted as an outbound ticket_message with the
 * WhatsApp message ID so delivery receipts can later be reconciled (§8.5).
 */
class WhatsAppOutboundService
{
    public function __construct(private readonly WhatsAppCloudApi $api) {}

    public function lastInboundAt(Employee $employee): ?Carbon
    {
        $ticketIds = $employee->tickets()->select('id');

        $max = TicketMessage::query()
            ->where('channel', MessageChannel::WhatsApp->value)
            ->where('direction', MessageDirection::Inbound->value)
            ->whereIn('ticket_id', $ticketIds)
            ->max('created_at');

        return $max !== null ? Carbon::parse($max) : null;
    }

    public function withinWindow(Employee $employee): bool
    {
        $last = $this->lastInboundAt($employee);

        return $last !== null
            && $last->gt(now()->subHours((int) config('services.whatsapp.session_window_hours', 24)));
    }

    /**
     * Best-effort notification for a ticket, applying the §11.2 rule.
     * Returns the persisted outbound message, or null when the message could not be sent.
     */
    public function notifyTicket(Ticket $ticket, Employee $employee, string $sessionBody, string $templateName, array $templateParams = []): ?TicketMessage
    {
        if ($this->withinWindow($employee)) {
            return $this->sendSession($ticket, $employee, $sessionBody);
        }

        if ($approved = $this->approvedTemplate($templateName)) {
            return $this->sendTemplate($ticket, $employee, $approved, $templateParams);
        }

        return $this->recordFailedWindowConstraint($ticket, $employee, $sessionBody);
    }

    public function sendSession(Ticket $ticket, Employee $employee, string $body): ?TicketMessage
    {
        try {
            $waId = $this->api->sendText($this->bareNumber($employee->whatsapp_number), $body);
        } catch (\Throwable) {
            return null;
        }

        return $this->record($ticket, $employee, $body, $waId, MessageType::Text, DeliveryStatus::Sent);
    }

    /**
     * One-off send with no associated ticket (unknown-sender guidance, greeting
     * replies). Nothing is persisted — inbound content is never stored for senders
     * we could not identify (§9.2, §13.1).
     */
    public function sendPlainText(string $number, string $body): bool
    {
        try {
            $this->api->sendText($this->bareNumber($number), $body);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function sendTemplate(Ticket $ticket, Employee $employee, WhatsAppTemplate $template, array $params): ?TicketMessage
    {
        try {
            $waId = $this->api->sendTemplate(
                $this->bareNumber($employee->whatsapp_number),
                $template->name,
                $template->language,
                array_map(fn (string $v) => ['text' => $v], $params),
            );
        } catch (\Throwable) {
            return null;
        }

        return $this->record($ticket, $employee, "template:{$template->name}", $waId, MessageType::Text, DeliveryStatus::Sent);
    }

    protected function recordFailedWindowConstraint(Ticket $ticket, Employee $employee, string $body): TicketMessage
    {
        return TicketMessage::create([
            'ticket_id' => $ticket->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::WhatsApp,
            'message_type' => MessageType::Text,
            'body' => $body,
            'delivery_status' => DeliveryStatus::Failed,
            'is_internal' => false,
            'metadata' => ['reason' => 'failed_window_constraint'],
        ]);
    }

    protected function record(Ticket $ticket, Employee $employee, string $body, string $waId, MessageType $type, DeliveryStatus $status): TicketMessage
    {
        return TicketMessage::create([
            'ticket_id' => $ticket->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::WhatsApp,
            'message_type' => $type,
            'body' => $body,
            'whatsapp_message_id' => $waId,
            'delivery_status' => $status,
            'is_internal' => false,
        ]);
    }

    protected function approvedTemplate(string $name): ?WhatsAppTemplate
    {
        return WhatsAppTemplate::query()
            ->where('name', $name)
            ->where('approval_status', 'approved')
            ->orderBy('language')
            ->first();
    }

    /** Cloud API expects a bare number without a leading plus. */
    protected function bareNumber(string $number): string
    {
        return ltrim($number, '+');
    }

    /**
     * Reconcile an inbound delivery-status receipt against the stored outbound
     * message (PRD §8.5, §11.4). Unknown message ids are ignored.
     */
    public function reconcileDelivery(string $whatsappMessageId, string $status): void
    {
        $mapped = match ($status) {
            'sent' => DeliveryStatus::Sent,
            'delivered' => DeliveryStatus::Delivered,
            'read' => DeliveryStatus::Read,
            'failed' => DeliveryStatus::Failed,
            default => null,
        };

        if ($mapped === null) {
            return;
        }

        TicketMessage::query()
            ->where('direction', MessageDirection::Outbound)
            ->where('whatsapp_message_id', $whatsappMessageId)
            ->update(['delivery_status' => $mapped->value]);
    }
}
