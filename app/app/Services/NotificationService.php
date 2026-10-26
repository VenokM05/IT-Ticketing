<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\DashboardNotification;
use App\Models\RelinkRequest;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\User;

/**
 * Creates per-user dashboard notifications with independent read state (PRD §7.8, FR-05).
 * One row per authorized recipient; idempotent against the
 * (user_id, type, ticket_id, ref_id) unique key so event redelivery never double-notifies.
 */
class NotificationService
{
    /**
     * New-ticket alert for every active IT administrator (FR-05).
     */
    public function ticketCreated(Ticket $ticket): void
    {
        $this->pushAllAdmins(
            type: NotificationType::NewTicket,
            ticket: $ticket,
            title: 'New ticket '.$ticket->ticket_number,
            message: $ticket->subject,
        );
    }

    /**
     * Assignment alert for the responsible technician (§3.3).
     */
    public function ticketAssigned(Ticket $ticket): void
    {
        $technician = $ticket->assignee;

        if ($technician instanceof User && $technician->is_active) {
            DashboardNotification::query()->firstOrCreate(
                [
                    'user_id' => $technician->id,
                    'type' => NotificationType::TicketAssigned->value,
                    'ticket_id' => $ticket->id,
                    'ref_id' => null,
                ],
                [
                    'title' => 'Assigned: '.$ticket->ticket_number,
                    'message' => $ticket->subject,
                ],
            );
        }
    }

    /**
     * Status-change alert for administrators, keyed by the history row so
     * repeat transitions of the same ticket each produce a notification (§7.8).
     */
    public function statusChanged(Ticket $ticket, TicketStatusHistory $history): void
    {
        $this->pushAllAdmins(
            type: NotificationType::StatusChanged,
            ticket: $ticket,
            title: $ticket->ticket_number.' → '.$history->to_status->label(),
            message: $history->remark ?? 'Status changed to '.$history->to_status->label(),
            refId: $history->id,
            data: ['from' => $history->from_status?->value, 'to' => $history->to_status->value],
        );
    }

    /**
     * Number-relink approval alert for every active administrator (PRD §9.3, §10.7).
     * Not tied to a ticket, so ticket_id is null and the relink request id is the ref.
     */
    public function relinkRequested(RelinkRequest $request): void
    {
        User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->each(fn (User $admin) => DashboardNotification::query()->firstOrCreate(
                [
                    'user_id' => $admin->id,
                    'type' => NotificationType::RelinkRequest->value,
                    'ticket_id' => null,
                    'ref_id' => $request->id,
                ],
                [
                    'title' => 'Number relink request',
                    'message' => 'Approval needed to link '.$request->requested_number.' to an employee.',
                    'data' => ['requested_number' => $request->requested_number],
                ],
            ));
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function pushAllAdmins(
        NotificationType $type,
        Ticket $ticket,
        string $title,
        ?string $message,
        ?int $refId = null,
        ?array $data = null,
    ): void {
        User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->each(fn (User $admin) => DashboardNotification::query()->firstOrCreate(
                [
                    'user_id' => $admin->id,
                    'type' => $type->value,
                    'ticket_id' => $ticket->id,
                    'ref_id' => $refId,
                ],
                [
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                ],
            ));
    }
}
