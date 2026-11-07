<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\KbArticle;
use App\Models\Priority;
use App\Models\Ticket;
use App\Support\ProblemMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
            ->with(['employee.department', 'company', 'assignee', 'priority', 'category'])
            ->when($filters['from'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $v) => $q->whereHas('priority', fn (Builder $p) => $p->where('key', $v)))
            ->when($filters['category'] ?? null, fn (Builder $q, string $v) => $q->whereHas('category', fn (Builder $c) => $c->where('key', $v)))
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

        // Reopen: a ticket that was resolved at some point (resolved_at set) but has
        // since fallen back to a live status. Rate is over the tickets ever resolved.
        $everResolved = $tickets->whereNotNull('resolved_at');
        $reopened = $everResolved->reject(fn (Ticket $t) => in_array($t->status, [TicketStatus::Resolved, TicketStatus::Closed], true))->count();

        $ratings = $tickets->whereNotNull('rating')->map(fn (Ticket $t) => (int) $t->rating);

        return [
            'first_response_avg_minutes' => $this->avg($firstResponse),
            'first_response_measured' => count($firstResponse),
            'resolution_avg_minutes' => $this->avg($resolution),
            'resolution_measured' => count($resolution),
            'resolution_max_minutes' => $resolution ? max($resolution) : null,
            'closure_avg_minutes' => $this->avg($closure),
            'closure_measured' => count($closure),
            'resolution_rate' => $tickets->isEmpty() ? 0.0 : round($resolvedOrClosed / $tickets->count() * 100, 1),
            'reopened' => $reopened,
            'reopen_rate' => $everResolved->isEmpty() ? 0.0 : round($reopened / $everResolved->count() * 100, 1),
            'csat_avg' => $ratings->isEmpty() ? null : round($ratings->avg(), 2),
            'csat_count' => $ratings->count(),
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
     * @return array<int, array{priority: Priority, count: int}>
     */
    public function byPriority(Collection $tickets): array
    {
        $counts = $tickets->countBy(fn (Ticket $t) => $t->priority?->key);

        return Priority::query()->ordered()->get()
            ->map(fn (Priority $p) => ['priority' => $p, 'count' => (int) ($counts[$p->key] ?? 0)])
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

    /*
    |--------------------------------------------------------------------------
    | Problem tally (recurring-issue dashboards)
    |--------------------------------------------------------------------------
    */

    /**
     * Recurring-problem tally for the dashboards: volumes grouped by category and
     * priority over a rolling window, weekly frequency trend per category, and a
     * two-tier repeat view — confirmed recurring (same normalised subject in a
     * category) and a fuzzy "related" tier for similarly worded complaints
     * (ProblemMatcher word overlap). Everything is derived in PHP like the rest
     * of the reports so sqlite tests and MySQL production agree. Passing a
     * `technician` or `company` filter scopes the whole tally, which is how the
     * technician dashboard and the admin company slice reuse it.
     *
     * The payload is analytic, not transactional, so it is cached for
     * config('tickets.tally.cache_ttl') seconds across dashboard poll cycles;
     * the key pins filters, window and date (the window still rolls at
     * midnight) and a TTL of 0 disables caching — what the test suite uses so
     * fixtures are always reflected immediately.
     *
     * @param  array<string, mixed>  $filters  same keys as query()
     * @return array<string, mixed>
     */
    public function problemTally(array $filters = [], ?int $weeks = null): array
    {
        $weeks = $weeks ?: (int) config('tickets.tally.weeks', 8);
        $ttl = (int) config('tickets.tally.cache_ttl', 90);

        $build = function () use ($filters, $weeks): array {
            // Monday-aligned so "this week" is always the newest trend bucket.
            $thisWeek = now()->startOfWeek(Carbon::MONDAY);
            $start = $thisWeek->copy()->subWeeks($weeks - 1);

            $tickets = $this->query($filters)->where('created_at', '>=', $start)->get();

            $categories = $this->tallyCategories($tickets, $start, $thisWeek);
            $recurring = $this->recurringSubjects($tickets);
            $repeatTickets = collect($recurring)->sum('count');

            return [
                'weeks' => $weeks,
                'start' => $start->toDateString(),
                'end' => $thisWeek->toDateString(),
                'totals' => [
                    'tickets' => $tickets->count(),
                    'categories' => count($categories),
                    'recurring' => count($recurring),
                    'repeat_rate' => $tickets->isEmpty() ? 0.0 : round($repeatTickets / $tickets->count() * 100, 1),
                ],
                'categories' => $categories,
                'priorities' => $this->tallyPriorities($tickets),
                'recurring' => $recurring,
                'related' => $this->relatedSubjects($tickets),
            ];
        };

        if ($ttl <= 0) {
            return $build();
        }

        $key = 'problem-tally:'.md5(json_encode([$filters, $weeks, now()->toDateString()]));

        return Cache::remember($key, $ttl, $build);
    }

    /**
     * Per-category tally row: volume, open/resolved split, share of the window,
     * week-over-week delta, zero-filled weekly trend, priority mix and the
     * average resolution time. Sorted by volume, most frequent problem first.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array<string, mixed>>
     */
    private function tallyCategories(Collection $tickets, Carbon $start, Carbon $thisWeek): array
    {
        $total = max(1, $tickets->count());
        $lastWeek = $thisWeek->copy()->subWeek();
        $priorities = Priority::query()->get()->keyBy('key');

        return $tickets
            ->groupBy(fn (Ticket $t) => $t->category?->key ?? 'uncategorised')
            ->map(function (Collection $group, string $key) use ($total, $start, $thisWeek, $lastWeek, $priorities) {
                $weekly = $group
                    ->groupBy(fn (Ticket $t) => $t->created_at->copy()->startOfWeek(Carbon::MONDAY)->toDateString())
                    ->map->count();

                // One bucket per window week, Monday-aligned, zero-filled.
                $trend = [];
                for ($week = $start->copy(); $week->lte($thisWeek); $week->addWeek()) {
                    $stamp = $week->toDateString();
                    $trend[] = ['week' => $stamp, 'count' => (int) ($weekly[$stamp] ?? 0)];
                }

                $thisCount = $group->filter(fn (Ticket $t) => $t->created_at->gte($thisWeek))->count();
                $lastCount = $group->filter(fn (Ticket $t) => $t->created_at->gte($lastWeek) && $t->created_at->lt($thisWeek))->count();

                $byPriority = $group
                    ->groupBy(fn (Ticket $t) => $t->priority?->key ?? 'unranked')
                    ->sortDesc()
                    ->map(fn (Collection $g, string $p) => [
                        'key' => $p,
                        'label' => $priorities[$p]?->label() ?? ucfirst($p),
                        'count' => $g->count(),
                    ])
                    ->values()
                    ->all();

                return [
                    'key' => $key,
                    'label' => $group->first()->category?->label() ?? 'Uncategorised',
                    'count' => $group->count(),
                    'open' => $group->where('status', '!=', TicketStatus::Closed)->count(),
                    'resolved' => $group->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed])->count(),
                    'share' => round($group->count() / $total * 100, 1),
                    'this_week' => $thisCount,
                    'last_week' => $lastCount,
                    'delta' => $thisCount - $lastCount,
                    'trend' => $trend,
                    'by_priority' => $byPriority,
                    'top_priority' => $byPriority[0] ?? null,
                    'avg_resolution_minutes' => $this->avg($this->durations($group, 'created_at', 'resolved_at')),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Priority-level tally: volume and open split per priority over the window,
     * with the dominant category and average resolution time per priority.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array<string, mixed>>
     */
    private function tallyPriorities(Collection $tickets): array
    {
        return $tickets
            ->groupBy(fn (Ticket $t) => $t->priority?->key ?? 'unranked')
            ->map(function (Collection $group, string $key) {
                return [
                    'key' => $key,
                    'label' => $group->first()->priority?->label() ?? ucfirst($key),
                    'count' => $group->count(),
                    'open' => $group->where('status', '!=', TicketStatus::Closed)->count(),
                    'top_category' => $group
                        ->countBy(fn (Ticket $t) => $t->category?->label() ?? 'Uncategorised')
                        ->sortDesc()
                        ->keys()
                        ->first(),
                    'avg_resolution_minutes' => $this->avg($this->durations($group, 'created_at', 'resolved_at')),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Confirmed recurring problems: tickets sharing one normalised subject inside
     * one category, at least config('tickets.tally.min_repeats') times (never
     * below 2 — a single ticket is never a repeat). Blank and symbol-only
     * subjects are excluded so they can never collapse into one bogus group.
     * Most repeated first.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array<string, mixed>>
     */
    private function recurringSubjects(Collection $tickets): array
    {
        $min = max(2, (int) config('tickets.tally.min_repeats', 2));
        $kb = $this->kbTitleIndex();

        return $tickets
            ->filter(fn (Ticket $t) => $this->normalizeSubject($t->subject) !== '')
            ->groupBy(fn (Ticket $t) => ($t->category?->key ?? 'uncategorised').'|'.$this->normalizeSubject($t->subject))
            ->filter(fn (Collection $group) => $group->count() >= $min)
            ->map(fn (Collection $group) => $this->tallyGroupRow($group, $kb))
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Fuzzy tier: the same complaint worded differently ("printer jam on 3rd
     * floor" vs "printer jams on 3rd floor"). A single greedy pass clusters
     * tickets per category by subject word-overlap (Jaccard) against the
     * cluster seed; only clusters spanning more than one distinct normalised
     * subject are surfaced, so pure exact repeats stay in the confirmed tier
     * alone. Confidence is honest: the UI labels these "possibly related".
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array<string, mixed>>
     */
    private function relatedSubjects(Collection $tickets): array
    {
        $threshold = (float) config('tickets.tally.similarity_threshold', 0.6);
        $kb = $this->kbTitleIndex();

        $clusters = [];
        foreach ($tickets->sortByDesc('created_at') as $ticket) {
            $tokens = ProblemMatcher::tokens($ticket->subject);
            if ($tokens === []) {
                continue;
            }

            $categoryKey = $ticket->category?->key ?? 'uncategorised';
            $joined = false;
            foreach (array_keys($clusters) as $i) {
                if ($clusters[$i]['category'] === $categoryKey
                    && ProblemMatcher::similarity($tokens, $clusters[$i]['tokens']) >= $threshold) {
                    $clusters[$i]['members'][] = $ticket;
                    $joined = true;
                    break;
                }
            }

            if (! $joined) {
                $clusters[] = ['category' => $categoryKey, 'tokens' => $tokens, 'members' => [$ticket]];
            }
        }

        return collect($clusters)
            ->map(fn (array $cluster) => collect($cluster['members']))
            ->filter(fn (Collection $members) => $members->count() >= 2
                && $members->map(fn (Ticket $t) => $this->normalizeSubject($t->subject))->unique()->count() > 1)
            ->map(fn (Collection $members) => $this->tallyGroupRow($members, $kb))
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Shape one repeat-problem group for display. Shared by the exact tier and
     * the fuzzy tier so both carry the same drill-down payload: requester
     * spread (1 person filing 5 tickets is one broken device; 5 people is an
     * infrastructure problem), ticket chips for exact drill-down, and a
     * published-KB hint so recurring problems point at self-help coverage.
     *
     * @param  Collection<int, Ticket>  $group
     * @param  list<array{title: string, category: ?string, tokens: list<string>}>  $kb
     * @return array<string, mixed>
     */
    private function tallyGroupRow(Collection $group, array $kb): array
    {
        $sorted = $group->sortByDesc('created_at');
        $latest = $sorted->first();
        $categoryKey = $latest->category?->key ?? 'uncategorised';

        return [
            'subject' => $latest->subject,
            'category_key' => $categoryKey,
            'category_label' => $latest->category?->label() ?? 'Uncategorised',
            'count' => $group->count(),
            'open' => $group->where('status', '!=', TicketStatus::Closed)->count(),
            'requesters' => $group->map(fn (Ticket $t) => $t->requesterName())->filter()->unique()->count(),
            'last_seen' => $latest->created_at,
            'ticket_ids' => $sorted->pluck('id')->take(5)->all(),
            'tickets' => $sorted->take(5)
                ->map(fn (Ticket $t) => ['id' => $t->id, 'number' => $t->ticket_number, 'status' => $t->status])
                ->values()
                ->all(),
            'kb_title' => $this->matchKbArticle($kb, $categoryKey, $this->normalizeSubject($latest->subject), ProblemMatcher::tokens($latest->subject)),
        ];
    }

    /**
     * Published KB articles as a tiny in-memory index (titles are short and the
     * table is curated, so this stays cheap next to the ticket scan).
     *
     * @return list<array{title: string, category: ?string, tokens: list<string>}>
     */
    private function kbTitleIndex(): array
    {
        return KbArticle::query()->published()->get(['title', 'category_key'])
            ->map(fn (KbArticle $article) => [
                'title' => (string) $article->title,
                'category' => $article->category_key,
                'tokens' => ProblemMatcher::tokens($article->title),
            ])
            ->filter(fn (array $entry) => $entry['tokens'] !== [])
            ->values()
            ->all();
    }

    /**
     * The published article covering a problem, if any: an exact normalised
     * title match wins outright, otherwise a same-category (or general)
     * article with strong word overlap counts as coverage.
     *
     * @param  list<array{title: string, category: ?string, tokens: list<string}>}  $articles
     * @param  list<string>  $subjectTokens
     */
    private function matchKbArticle(array $articles, string $categoryKey, string $normalizedSubject, array $subjectTokens): ?string
    {
        $probable = null;

        foreach ($articles as $article) {
            if ($article['category'] !== null && $article['category'] !== $categoryKey) {
                continue;
            }

            if (implode(' ', $article['tokens']) === $normalizedSubject) {
                return $article['title'];
            }

            if ($probable === null && ProblemMatcher::similarity($article['tokens'], $subjectTokens) >= 0.8) {
                $probable = $article['title'];
            }
        }

        return $probable;
    }

    /**
     * Subject key for repeat detection: lower-cased, punctuation flattened to
     * spaces, whitespace collapsed, so "WiFi is down!!" and "wifi is down" match.
     */
    private function normalizeSubject(?string $subject): string
    {
        return ProblemMatcher::normalized($subject);
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
                'rating' => $t->rating,
                'rating_comment' => $t->rating_comment,
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
