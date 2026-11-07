<?php

use App\Models\Company;
use App\Services\ReportService;
use Livewire\Volt\Component;

new class extends Component {
    public int $weeks = 8;

    public string $company = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->weeks = (int) config('tickets.tally.weeks', 8);

        // Same convenience default as the reports page: company staff land on
        // their own slice, platform admins see everything.
        $this->company = (string) (auth()->user()?->company_id ?? '');
    }

    /**
     * Org-wide (or single-company) recurring-problem tally over the selected
     * rolling window. The authorization is re-checked at render, not just on
     * mount, so a role change mid-session can never leak the admin view. The
     * service caches the payload across polls (tickets.tally.cache_ttl).
     */
    public function with(): array
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return [
            'tally' => app(ReportService::class)->problemTally(array_filter(['company' => $this->company]), $this->weeks),
            'companies' => Company::query()->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="card p-5">
    {{-- Masthead: company + window slicers, CSV export, pointer to the full report --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold">Problem Tally</h2>
            <p class="text-xs text-fg-subtle">
                Recurring issues {{ $company !== '' ? 'for one company' : 'across all companies' }} · last {{ $tally['weeks'] }} weeks
                ({{ \Illuminate\Support\Carbon::parse($tally['start'])->format('j M') }} – {{ \Illuminate\Support\Carbon::parse($tally['end'])->format('j M Y') }})
            </p>
        </div>
        <div class="flex items-center gap-2">
            <flux:select wire:model.live="company" size="sm" class="w-40" aria-label="Filter by company">
                <flux:select.option value="">All companies</flux:select.option>
                @foreach ($companies as $item)
                    <flux:select.option value="{{ $item->id }}">{{ $item->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="weeks" size="sm" class="w-28" aria-label="Tally window">
                <flux:select.option value="4">4 weeks</flux:select.option>
                <flux:select.option value="8">8 weeks</flux:select.option>
                <flux:select.option value="12">12 weeks</flux:select.option>
                <flux:select.option value="26">26 weeks</flux:select.option>
            </flux:select>
            {{-- Streams the same sections as the on-screen card via the report export route. --}}
            <flux:button size="sm" variant="subtle" icon="arrow-down-tray" :href="route('reports.export', array_filter(['type' => 'tally', 'weeks' => $this->weeks, 'company' => $this->company]))" wire:navigate.off>CSV</flux:button>
            <flux:button size="sm" variant="subtle" :href="route('reports')" wire:navigate>Full report</flux:button>
        </div>
    </div>

    {{-- Headline summary cards --}}
    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface-muted/50 p-3">
            <div class="text-xs text-fg-muted">Requests in window</div>
            <div class="mt-1 text-xl font-semibold tabular-nums">{{ $tally['totals']['tickets'] }}</div>
        </div>
        <div class="rounded-lg border border-border bg-surface-muted/50 p-3">
            <div class="text-xs text-fg-muted">Top problem type</div>
            @if ($tally['categories'] !== [])
                <a href="{{ route('tickets', array_filter(['category' => $tally['categories'][0]['key'], 'company' => $this->company])) }}" wire:navigate class="mt-1 block truncate text-xl font-semibold text-accent-content hover:underline">
                    {{ $tally['categories'][0]['label'] }}
                    <span class="text-sm font-normal text-fg-muted">({{ $tally['categories'][0]['count'] }})</span>
                </a>
            @else
                <div class="mt-1 text-xl font-semibold text-fg-subtle">—</div>
            @endif
        </div>
        <div class="rounded-lg border border-border bg-surface-muted/50 p-3">
            <div class="text-xs text-fg-muted">Recurring issues</div>
            <div class="mt-1 text-xl font-semibold tabular-nums {{ $tally['totals']['recurring'] > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ $tally['totals']['recurring'] }}</div>
        </div>
        <div class="rounded-lg border border-border bg-surface-muted/50 p-3">
            <div class="text-xs text-fg-muted">Repeat rate</div>
            <div class="mt-1 text-xl font-semibold tabular-nums">{{ $tally['totals']['repeat_rate'] }}%</div>
        </div>
    </div>

    @if ($tally['totals']['tickets'] === 0)
        <div class="mt-4 flex flex-col items-center gap-3 py-6 text-center">
            <p class="text-sm text-fg-subtle">No requests in the last {{ $tally['weeks'] }} weeks.</p>
            @if ($tally['weeks'] < 26)
                {{-- Gentle nudge: the window is probably the problem, not the traffic. --}}
                <flux:button wire:click="$set('weeks', 26)" size="sm" variant="subtle">Widen to 26 weeks</flux:button>
            @endif
        </div>
    @else
        <div class="mt-5 grid gap-5 xl:grid-cols-3">
            {{-- Category tallies: bar, weekly trend sparkline, week delta, priority mix --}}
            <div class="xl:col-span-2">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted">Problem types by volume</h3>
                <div class="mt-2 divide-y divide-border">
                    @foreach ($tally['categories'] as $row)
                        @php($trendMax = max(1, collect($row['trend'])->max('count')))
                        <div class="group py-3">
                            <div class="flex items-center justify-between gap-3">
                                <a href="{{ route('tickets', array_filter(['category' => $row['key'], 'company' => $this->company])) }}" wire:navigate class="text-sm font-medium text-accent-content hover:underline">
                                    {{ $row['label'] }}
                                </a>
                                <div class="flex items-center gap-2">
                                    @if ($row['delta'] > 0)
                                        <span class="text-xs font-medium text-amber-600 dark:text-amber-400" title="vs last week">▲ {{ $row['delta'] }}</span>
                                    @elseif ($row['delta'] < 0)
                                        <span class="text-xs font-medium text-green-600 dark:text-green-400" title="vs last week">▼ {{ abs($row['delta']) }}</span>
                                    @endif
                                    <span class="text-sm font-semibold tabular-nums">{{ $row['count'] }}</span>
                                    <span class="text-xs text-fg-subtle">({{ $row['share'] }}%)</span>
                                </div>
                            </div>
                            <div class="mt-1.5 h-2 rounded bg-surface-muted">
                                <div class="h-2 rounded bg-accent" style="width: {{ (int) round($row['count'] / max(1, $tally['categories'][0]['count']) * 100) }}%"></div>
                            </div>
                            <div class="mt-2 flex items-end justify-between gap-3">
                                {{-- Weekly frequency pattern (sparkline bars) --}}
                                <div class="flex-1">
                                    <div class="flex h-8 items-end gap-px" title="Weekly volume">
                                        @foreach ($row['trend'] as $bucket)
                                            <div class="flex-1 rounded-t bg-accent/60 transition group-hover:bg-accent" style="height: {{ max(8, (int) round($bucket['count'] / $trendMax * 100)) }}%" title="{{ $bucket['week'] }}: {{ $bucket['count'] }}"></div>
                                        @endforeach
                                    </div>
                                    {{-- Readable caption so the sparklines need no hovering --}}
                                    <div class="mt-1 text-[10px] text-fg-subtle">
                                        weekly {{ \Illuminate\Support\Carbon::parse($row['trend'][0]['week'])->format('j M') }} → {{ \Illuminate\Support\Carbon::parse(collect($row['trend'])->last()['week'])->format('j M') }}
                                    </div>
                                </div>
                                {{-- Priority mix chips (each drills into category+priority) --}}
                                <div class="flex flex-wrap items-center justify-end gap-1">
                                    @foreach ($row['by_priority'] as $pri)
                                        <a href="{{ route('tickets', array_filter(['category' => $row['key'], 'priority' => $pri['key'], 'company' => $this->company])) }}" wire:navigate>
                                            <flux:badge size="sm" :color="$pri['key'] === 'critical' ? 'red' : ($pri['key'] === 'high' ? 'orange' : 'zinc')" class="capitalize">{{ $pri['label'] }} · {{ $pri['count'] }}</flux:badge>
                                        </a>
                                    @endforeach
                                </div>
                                <div class="hidden w-36 shrink-0 text-right text-xs text-fg-subtle sm:block">
                                    {{ $row['open'] }} open · {{ \App\Services\ReportService::humanize($row['avg_resolution_minutes']) }} avg fix
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Priority-level tally + repeat offenders (exact + fuzzy tiers) --}}
            <div class="flex flex-col gap-5">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted">By priority level</h3>
                    <table class="mt-2 w-full text-sm">
                        <tbody class="divide-y divide-border">
                            @foreach ($tally['priorities'] as $pri)
                                <tr>
                                    <td class="py-1.5">
                                        <a href="{{ route('tickets', array_filter(['priority' => $pri['key'], 'company' => $this->company])) }}" wire:navigate class="text-accent-content hover:underline">{{ $pri['label'] }}</a>
                                        <div class="text-xs text-fg-subtle">{{ $pri['top_category'] }} · {{ $pri['open'] }} open</div>
                                    </td>
                                    <td class="py-1.5 text-right tabular-nums font-medium">{{ $pri['count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted">Most repeated problems</h3>
                    <div class="mt-2 space-y-2">
                        @forelse ($tally['recurring'] as $issue)
                            <div class="rounded-lg border border-border p-2.5">
                                <a href="{{ route('tickets', array_filter(['search' => $issue['subject'], 'company' => $this->company])) }}" wire:navigate class="text-sm font-medium text-accent-content hover:underline">{{ $issue['subject'] }}</a>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-fg-subtle">
                                    <a href="{{ route('tickets', array_filter(['category' => $issue['category_key'], 'company' => $this->company])) }}" wire:navigate class="text-fg-muted hover:underline">{{ $issue['category_label'] }}</a>
                                    <span>×{{ $issue['count'] }}</span>
                                    {{-- Spread tells device failures (1 requester) from incidents (many). --}}
                                    <flux:badge size="sm" :color="$issue['requesters'] > 1 ? 'amber' : 'zinc'">{{ $issue['requesters'] }} requester{{ $issue['requesters'] === 1 ? '' : 's' }}</flux:badge>
                                    @if ($issue['open'] > 0)
                                        <flux:badge size="sm" color="amber">{{ $issue['open'] }} open</flux:badge>
                                    @endif
                                    <span>last {{ $issue['last_seen']->diffForHumans() }}</span>
                                </div>
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    @if ($issue['kb_title'])
                                        <a href="{{ route('settings.knowledge-base') }}" wire:navigate title="Covered by a published KB article">
                                            <flux:badge size="sm" color="green" icon="book-open">KB: {{ \Illuminate\Support\Str::limit($issue['kb_title'], 26) }}</flux:badge>
                                        </a>
                                    @else
                                        <a href="{{ route('settings.knowledge-base') }}" wire:navigate title="No KB article covers this yet — consider drafting one">
                                            <flux:badge size="sm" color="zinc">Draft a KB article</flux:badge>
                                        </a>
                                    @endif
                                    @if ($issue['requesters'] > 1 && $issue['open'] > 0)
                                        {{-- Incident candidate: one click prefills the broadcast draft. --}}
                                        <flux:button size="xs" variant="subtle" icon="megaphone" :href="route('broadcast', ['subject' => $issue['subject']])" wire:navigate>Escalate</flux:button>
                                    @endif
                                </div>
                                {{-- Exact drill-down: the specific tickets behind the tally. --}}
                                <div class="mt-1.5 flex flex-wrap gap-1">
                                    @foreach ($issue['tickets'] as $t)
                                        <a href="{{ route('tickets.show', $t['id']) }}" wire:navigate class="rounded border border-border px-1.5 py-0.5 text-[11px] tabular-nums text-accent-content hover:underline">{{ $t['number'] }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-fg-subtle">No repeated problems in this window.</p>
                        @endforelse
                    </div>
                </div>

                @if ($tally['related'] !== [])
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted">Possibly related wording</h3>
                        <p class="mt-1 text-[11px] text-fg-subtle">Similar complaints filed under different words — candidates for the same root cause.</p>
                        <div class="mt-2 space-y-2">
                            @foreach (array_slice($tally['related'], 0, 4) as $issue)
                                <div class="rounded-lg border border-border p-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-medium">{{ $issue['subject'] }}</span>
                                        <flux:badge size="sm" color="zinc">×{{ $issue['count'] }}</flux:badge>
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-fg-subtle">
                                        <span>{{ $issue['category_label'] }}</span>
                                        <span>{{ $issue['requesters'] }} requester{{ $issue['requesters'] === 1 ? '' : 's' }}</span>
                                        @foreach ($issue['tickets'] as $t)
                                            <a href="{{ route('tickets.show', $t['id']) }}" wire:navigate class="rounded border border-border px-1.5 py-0.5 text-[11px] tabular-nums text-accent-content hover:underline">{{ $t['number'] }}</a>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
