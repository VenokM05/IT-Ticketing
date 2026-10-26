<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

/**
 * Role-based ticket authorization (PRD FR-13, §3.2, §3.3).
 * Auto-discovered for App\Models\Ticket.
 */
class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->isAdmin() || $ticket->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    /**
     * Assigning/reassigning technicians is an administrator action (FR-06).
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    /**
     * Change status/priority/category. Admins anywhere; technicians only on their
     * own, non-closed tickets (FR-07, §3.3).
     */
    public function update(User $user, Ticket $ticket): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $ticket->assigned_to === $user->id && $ticket->status !== TicketStatus::Closed;
    }

    public function addProgress(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket);
    }

    /**
     * Closed tickets require an explicitly authorized reopen (FR-07).
     */
    public function reopen(User $user, Ticket $ticket): bool
    {
        return $user->is_active && $user->isAdmin() && $ticket->status === TicketStatus::Closed;
    }
}
