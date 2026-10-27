<?php

namespace App\Services;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Operational reporting (PRD Phase 6, §10.6). Builds one filtered ticket query and
 * derives every report section from it in PHP so the math is identical across the
 * sqlite test DB and MySQL production (no dialect-specific date functions).
 */
class ReportService
{
    /**
     * A shared, filter-aware ticket query used by both the report page and CSV export.
     *
     * @param  array<string, mixed>  $filters  keys: from,to,status,priority,category,technician,department,company
     */
    public function query(array $filters = []): Builder
    {
        return Ticket::query()
            ->with(['employee.department', 'company', 'assignee'])
            ->when($filters['from'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $v) => $q->where('priority', $v))
            ->when($filters['category'] ?? null, fn (Builder $q, string $v) => $q->where('category', $v))
            ->when($filters['technician'] ?? null, fn (Builder $q, string $v) => $q->where('assigned_to', $v))
            ->when($filters['department'] ?? null, fn (Builder $q, string $v) => $q->whereHas('employee', fn (Builder $e) => $e->where('department_id', $v)))
            ->when($filters['company'] ?? null, fn (Builder $q, string $v) => $q->where('company_id', $v));
    }

    /**
     * Every report section at once. Loads the filtered set a single time.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters = []): array
    {
        $tickets = $this->query($filters)->orderBy('created_at')->get();

        return [
            'filters' => $filters,
            'totals' => $this->totals($tickets),
            'metrics' => $this->metrics($tickets),
            'by_status' => $this->byStatus($tickets),
            'by_priority' => $this->byPriority($tickets),
            'by_category' => $this->byCategory($tickets),
            'by_company' => $this->byCompany($tickets),
            'by_department' => $this->byDepartment($tickets),
            'workload' => $this->technicianWorkload($tickets),
            'daily' => $this->trend($tickets, 'day'),
            'monthly' => $this->trend($tickets, 'month'),
        ];
    }

    /**
     * Headline counts over the created-at window.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<string, int>
     */
    public function totals(Collection $tickets): array
    {
        return [
            'created' => $tickets->count(),
            'open' => $tickets->where('status', '!=', TicketStatus::Closed)->count(),
            'resolved' => $tickets->where('status', TicketStatus::Resolved)->count(),
            'closed' => $tickets->where('status', TicketStatus::Closed)->count(),
            'unassigned' => $tickets->whereNull('assigned_to')->count(),
        ];
    }

    /**
     * Response and resolution metrics, averaged over only the tickets that have
     * the relevant milestone timestamp set.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<string, float|int|null>
     */
    public function metrics(Collection $tickets): array
    {
        $firstResponse = $this->durations($tickets, 'created_at', 'first_response_at');
        $resolution = $this->durations($tickets, 'created_at', 'resolved_at');
        $closure = $this->durations($tickets, 'created_at', 'closed_at');

        $resolvedOrClosed = $tickets->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed])->count();

        return [
            'first_response_avg_minutes' => $this->avg($firstResponse),
            'first_response_measured' => count($firstResponse),
            'resolution_avg_minutes' => $this->avg($resolution),
            'resolution_measured' => count($resolution),
            'resolution_max_minutes' => $resolution ? max($resolution) : null,
            'closure_avg_minutes' => $this->avg($closure),
            'closure_measured' => count($closure),
            'resolution_rate' => $tickets->isEmpty() ? 0.0 : round($resolvedOrClosed / $tickets->count() * 100, 1),
        ];
    }

    /**
     * Current-status distribution.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{status: TicketStatus, count: int}>
     */
    public function byStatus(Collection $tickets): array
    {
        $counts = $tickets->countBy(fn (Ticket $t) => $t->status->value);

        return collect(TicketStatus::cases())
            ->map(fn (TicketStatus $s) => ['status' => $s, 'count' => (int) ($counts[$s->value] ?? 0)])
            ->all();
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{priority: TicketPriority, count: int}>
     */
    public function byPriority(Collection $tickets): array
    {
        $counts = $tickets->countBy(fn (Ticket $t) => $t->priority->value);

        return collect(TicketPriority::cases())
            ->map(fn (TicketPriority $p) => ['priority' => $p, 'count' => (int) ($counts[$p->value] ?? 0)])
            ->all();
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{category: string, count: int}>
     */
    public function byCategory(Collection $tickets): array
    {
        return $tickets
            ->groupBy(fn (Ticket $t) => $t->category->label())
            ->map(fn (Collection $g, string $label) => ['category' => $label, 'count' => $g->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Volume per company (multi-tenant tagging), most tickets first.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{company: string, count: int, open: int, resolved: int}>
     */
    public function byCompany(Collection $tickets): array
    {
        return $tickets
            ->groupBy(fn (Ticket $t) => $t->company?->name ?? 'Unassigned')
            ->map(fn (Collection $g, string $name) => [
                'company' => $name,
                'count' => $g->count(),
                'open' => $g->where('status', '!=', TicketStatus::Closed)->count(),
                'resolved' => $g->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed])->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{department: string, count: int, open: int}>
     */
    public function byDepartment(Collection $tickets): array
    {
        return $tickets
            ->groupBy(fn (Ticket $t) => $t->employee?->department?->name ?? 'Unassigned')
            ->map(fn (Collection $g, string $name) => [
                'department' => $name,
                'count' => $g->count(),
                'open' => $g->where('status', '!=', TicketStatus::Closed)->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Per-technician workload: assigned volume plus resolution performance.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{technician: string, assigned: int, open: int, resolved: int, avg_resolution_minutes: float|null}>
     */
    public function technicianWorkload(Collection $tickets): array
    {
        return $tickets
            ->groupBy(fn (Ticket $t) => $t->assignee?->name ?? 'Unassigned')
            ->map(function (Collection $g, string $name) {
                $resolved = $g->filter(fn (Ticket $t) => $t->resolved_at !== null);

                return [
                    'technician' => $name,
                    'assigned' => $g->count(),
                    'open' => $g->where('status', '!=', TicketStatus::Closed)->count(),
                    'resolved' => $resolved->count(),
                    'avg_resolution_minutes' => $this->avg($this->durations($resolved, 'created_at', 'resolved_at')),
                ];
            })
            ->sortByDesc('assigned')
            ->values()
            ->all();
    }

    /**
     * Created vs resolved vs closed counts bucketed by period (day or month).
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{period: string, created: int, resolved: int, closed: int}>
     */
    public function trend(Collection $tickets, string $granularity = 'day'): array
    {
        $format = $granularity === 'month' ? 'Y-m' : 'Y-m-d';

        $created = $tickets->groupBy(fn (Ticket $t) => $t->created_at->format($format))->map->count();
        $resolved = $tickets->filter(fn (Ticket $t) => $t->resolved_at !== null)
            ->groupBy(fn (Ticket $t) => $t->resolved_at->format($format))->map->count();
        $closed = $tickets->filter(fn (Ticket $t) => $t->closed_at !== null)
            ->groupBy(fn (Ticket $t) => $t->closed_at->format($format))->map->count();

        $periods = $created->keys()->merge($resolved->keys())->merge($closed->keys())->unique()->sort()->values();

        return $periods->map(fn (string $p) => [
            'period' => $p,
            'created' => (int) ($created[$p] ?? 0),
            'resolved' => (int) ($resolved[$p] ?? 0),
            'closed' => (int) ($closed[$p] ?? 0),
        ])->all();
    }

    /**
     * Flat rows for the CSV export.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, string|int|null>>
     */
    public function csvRows(array $filters = []): array
    {
        return $this->query($filters)->orderBy('created_at')->get()
            ->map(fn (Ticket $t) => [
                'ticket_number' => $t->ticket_number,
                'subject' => $t->subject,
                'employee' => $t->employee?->name,
                'company' => $t->company?->name,
                'department' => $t->employee?->department?->name,
                'category' => $t->category->label(),
                'priority' => $t->priority->label(),
                'status' => $t->status->label(),
                'technician' => $t->assignee?->name,
                'created_at' => $this->stamp($t->created_at),
                'first_response_at' => $this->stamp($t->first_response_at),
                'resolved_at' => $this->stamp($t->resolved_at),
                'closed_at' => $this->stamp($t->closed_at),
                'resolution_minutes' => $t->resolved_at ? (int) round($t->created_at->diffInMinutes($t->resolved_at)) : null,
            ])
            ->all();
    }

    /**
     * Render a minute count as a compact human duration (shared by the report
     * page and the scheduled weekly summary).
     */
    public static function humanize(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $m = (int) round($minutes);
        if ($m < 60) {
            return "{$m} min";
        }

        $h = intdiv($m, 60);
        $r = $m % 60;
        if ($h < 24) {
            return "{$h}h {$r}m";
        }

        return intdiv($h, 24).'d '.($h % 24).'h';
    }

    /**
     * Minutes between two nullable timestamps on each ticket that has both set.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, int>
     */
    private function durations(Collection $tickets, string $fromKey, string $toKey): array
    {
        return $tickets
            ->filter(fn (Ticket $t) => $t->$fromKey !== null && $t->$toKey !== null)
            ->map(fn (Ticket $t) => (int) round($t->$fromKey->diffInMinutes($t->$toKey)))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $values
     */
    private function avg(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }

    private function stamp(?Carbon $moment): ?string
    {
        return $moment?->format('Y-m-d H:i');
    }
}
