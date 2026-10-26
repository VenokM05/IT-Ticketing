<?php

namespace App\Enums;

enum TicketStatus: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * The allowed next statuses for each current status (PRD §5.2).
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Assigned, self::InProgress, self::Pending, self::Closed],
            self::Assigned => [self::InProgress, self::Pending, self::Resolved, self::Closed],
            self::InProgress => [self::Pending, self::Resolved, self::Closed],
            self::Pending => [self::Assigned, self::InProgress, self::Resolved, self::Closed],
            self::Resolved => [self::InProgress, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * A ticket is considered open (still being worked) until it is Closed.
     */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Assigned => 'Assigned',
            self::InProgress => 'In Progress',
            self::Pending => 'Pending',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }
}
