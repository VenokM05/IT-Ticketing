<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Daily reminder for ticket requests still awaiting IT action (process.md item 6:
 * "alert every 5PM if there's a pending IT ticket request"). Pushed to every active
 * administrator's dashboard notification feed, grouped by company.
 */
class PendingTicketAlert extends Command
{
    protected $signature = 'tickets:pending-alert {--force : send the alert even when nothing is pending}';

    protected $description = 'Alert IT administrators about ticket requests still awaiting action';

    public function handle(NotificationService $notifications): int
    {
        $statuses = $this->pendingStatuses();

        $pending = Ticket::query()
            ->with('company:id,name')
            ->whereIn('status', $statuses)
            ->orderBy('created_at')
            ->get();

        if ($pending->isEmpty() && ! $this->option('force')) {
            $this->info('Nothing pending — no alert sent.');

            return self::SUCCESS;
        }

        $byCompany = $pending->groupBy(fn (Ticket $t) => $t->company?->name ?? 'Unassigned');

        $notifications->adminAlert(
            type: NotificationType::PendingTicketsAlert,
            refId: (int) now(config('alerts.timezone'))->format('Ymd'),
            title: $pending->isEmpty()
                ? 'No pending ticket requests'
                : "Pending ticket requests: {$pending->count()}",
            message: $byCompany->isEmpty()
                ? 'All ticket requests are resolved or closed.'
                : $byCompany->map(fn ($group, string $name) => "{$name}: {$group->count()}")->implode(' · '),
            data: [
                'pending' => $pending->count(),
                'by_company' => $byCompany->map->count()->all(),
                'oldest_created_at' => $pending->first()?->created_at?->toDateTimeString(),
            ],
        );

        $this->info("Pending alert sent ({$pending->count()} ticket(s) awaiting action).");

        return self::SUCCESS;
    }

    /**
     * Statuses treated as "pending", from config, defaulted to everything not
     * resolved or closed.
     *
     * @return list<string>
     */
    private function pendingStatuses(): array
    {
        $configured = collect((array) config('alerts.pending.statuses'))
            ->map(fn (string $value) => TicketStatus::tryFrom($value)?->value)
            ->filter()
            ->values()
            ->all();

        return $configured !== []
            ? $configured
            : array_map(
                fn (TicketStatus $case) => $case->value,
                array_diff(TicketStatus::cases(), [TicketStatus::Resolved, TicketStatus::Closed]),
            );
    }
}
