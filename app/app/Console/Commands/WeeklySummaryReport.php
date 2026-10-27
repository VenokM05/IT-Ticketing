<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Services\NotificationService;
use App\Services\ReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Weekly monitoring summary for Friday review (process.md item 5). Aggregates the
 * current week through ReportService — the same source the on-screen report and CSV
 * export use — then publishes it to every active administrator's notification feed.
 */
class WeeklySummaryReport extends Command
{
    protected $signature = 'tickets:weekly-summary
                            {--from= : week start date (Y-m-d), defaults to Monday}
                            {--to= : window end date (Y-m-d), defaults to today}';

    protected $description = 'Publish the weekly ticket monitoring summary for administrator review';

    public function handle(NotificationService $notifications, ReportService $reports): int
    {
        $now = now(config('alerts.timezone'));

        $from = $this->option('from') ?: $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $to = $this->option('to') ?: $now->toDateString();

        $report = $reports->report(['from' => $from, 'to' => $to]);
        $totals = $report['totals'];
        $metrics = $report['metrics'];

        $companies = collect($report['by_company'])
            ->take(3)
            ->map(fn (array $row) => "{$row['company']}: {$row['count']}")
            ->implode(' · ');

        $notifications->adminAlert(
            type: NotificationType::WeeklySummary,
            refId: (int) $now->format('Ymd'),
            title: "Weekly ticket summary {$from} → {$to}",
            message: trim(
                "Created {$totals['created']} · Resolved {$totals['resolved']} · Closed {$totals['closed']}"
                ." · Still open {$totals['open']}"
                ." · Avg resolution ".ReportService::humanize($metrics['resolution_avg_minutes'])
                .($companies !== '' ? " · Top companies: {$companies}" : '')
            ),
            data: [
                'from' => $from,
                'to' => $to,
                'totals' => $totals,
                'metrics' => $metrics,
                'by_company' => $report['by_company'],
                'workload' => $report['workload'],
            ],
        );

        $this->info("Weekly summary ({$from} → {$to}) published to administrators.");

        return self::SUCCESS;
    }
}
