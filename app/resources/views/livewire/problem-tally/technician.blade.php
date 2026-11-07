<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\ReportService;
use Livewire\Volt\Component;

new class extends Component {
    /**
     * The same recurring-problem maths as the admin tally, scoped to this
     * technician's own queue via the ReportService `technician` filter, so the
     * page surfaces what they keep fixing rather than org-wide trends.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        $tally = app(ReportService::class)->problemTally(
            ['technician' => (string) $user->id],
            (int) config('tickets.tally.weeks', 8),
        );

        return [
            'tally' => $tally,
            // Actionable tail: the oldest live tickets in the most frequent
            // category still waiting on this technician.
            'nextUp' => $this->nextUp($user->id, $tally['categories'][0]['key'] ?? null),
        ];
    }

    /**
     * Up to five open tickets from the technician's top recurring category,
     * oldest first — the "start here" list under the tally.
     *
     * @return list<array{id: int, number: string, subject: string, status: TicketStatus}>
     */
    private function nextUp(int $userId, ?string $topCategoryKey): array
    {
        if ($topCategoryKey === null) {
            return [];
        }

        return Ticket::query()
            ->assignedTo($userId)
            ->where('status', '!=', TicketStatus::Closed->value)
            ->whereHas('category', fn ($q) => $q->where('key', $topCategoryKey))
            ->oldest()
            ->limit(5)
            ->get()
            ->map(fn (Ticket $t) => [
                'id' => $t->id,
                'number' => $t->ticket_number,
                'subject' => $t->subject,
                'status' => $t->status,
            ])
            ->all();
    }
}; ?>

<div class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold">My Problem Tally</h2>
            <p class="text-xs text-fg-subtle">Recurring issues in your queue · last {{ $tally['weeks'] }} weeks</p>
        </div>
        <flux:button size="sm" variant="subtle" :href="route('tickets')" wire:navigate>My tickets</flux:button>
    </div>

    @if ($tally['totals']['tickets'] === 0)
        <p class="mt-4 py-6 text-center text-sm text-fg-subtle">No tickets assigned to you in the last {{ $tally['weeks'] }} weeks.</p>
    @else
        {{-- Top problem types at a glance; each card drills into the filtered list --}}
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ($tally['categories'] as $row)
                @php($trendMax = max(1, collect($row['trend'])->max('count')))
                <a href="{{ route('tickets', ['category' => $row['key']]) }}" wire:navigate
                   class="rounded-lg border border-border p-3 transition hover:border-accent/40 hover:shadow-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-accent">
                    <div class="flex items-center justify-between">
                        <span class="truncate text-sm font-medium">{{ $row['label'] }}</span>
                        @if ($row['delta'] > 0)
                            <span class="text-xs font-medium text-amber-600 dark:text-amber-400" title="vs last week">▲ {{ $row['delta'] }}</span>
                        @endif
                    </div>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-2xl font-semibold tabular-nums">{{ $row['count'] }}</span>
                        <span class="text-xs text-fg-subtle">{{ $row['open'] }} open</span>
                    </div>
                    <div class="mt-2 flex h-6 items-end gap-px">
                        @foreach ($row['trend'] as $bucket)
                            <div class="flex-1 rounded-t bg-accent/60" style="height: {{ max(12, (int) round($bucket['count'] / $trendMax * 100)) }}%" title="{{ $bucket['week'] }}: {{ $bucket['count'] }}"></div>
                        @endforeach
                    </div>
                    <div class="mt-1 text-[10px] text-fg-subtle">
                        {{ \Illuminate\Support\Carbon::parse($row['trend'][0]['week'])->format('j M') }} → {{ \Illuminate\Support\Carbon::parse(collect($row['trend'])->last()['week'])->format('j M') }}
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-5 grid gap-5 lg:grid-cols-2">
            {{-- Repeat offenders in this queue: same complaint, multiple times --}}
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted">You keep fixing</h3>
                <div class="mt-2 space-y-2">
                    @forelse ($tally['recurring'] as $issue)
                        <div class="rounded-lg border border-border p-2.5">
                            <div class="flex items-center justify-between gap-2">
                                <a href="{{ route('tickets', ['search' => $issue['subject']]) }}" wire:navigate class="truncate text-sm font-medium text-accent-content hover:underline">{{ $issue['subject'] }}</a>
                                <flux:badge size="sm" :color="$issue['open'] > 0 ? 'amber' : 'green'">×{{ $issue['count'] }}</flux:badge>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-fg-subtle">
                                <a href="{{ route('tickets', ['category' => $issue['category_key']]) }}" wire:navigate class="text-fg-muted hover:underline">{{ $issue['category_label'] }}</a>
                                {{-- Many requesters means infrastructure, not one broken device. --}}
                                <span>{{ $issue['requesters'] }} requester{{ $issue['requesters'] === 1 ? '' : 's' }}</span>
                                <span>last seen {{ $issue['last_seen']->diffForHumans() }}</span>
                                @if ($issue['kb_title'])
                                    <a href="{{ route('settings.knowledge-base') }}" wire:navigate title="A published KB article covers this">
                                        <flux:badge size="sm" color="green" icon="book-open">KB</flux:badge>
                                    </a>
                                @endif
                            </div>
                            {{-- Exact tickets behind the tally, for one-click follow-up. --}}
                            <div class="mt-1.5 flex flex-wrap gap-1">
                                @foreach ($issue['tickets'] as $t)
                                    <a href="{{ route('tickets.show', $t['id']) }}" wire:navigate class="rounded border border-border px-1.5 py-0.5 text-[11px] tabular-nums text-accent-content hover:underline">{{ $t['number'] }}</a>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-fg-subtle">No repeated problems in your queue.</p>
                    @endforelse
                </div>

                @if ($tally['related'] !== [])
                    {{-- Same complaint worded differently — worth a shared fix. --}}
                    <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-fg-muted">Also related (similar wording)</h3>
                    <div class="mt-2 space-y-2">
                        @foreach (array_slice($tally['related'], 0, 3) as $issue)
                            <div class="rounded-lg border border-border p-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm">{{ $issue['subject'] }}</span>
                                    <flux:badge size="sm" color="zinc">×{{ $issue['count'] }}</flux:badge>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($issue['tickets'] as $t)
                                        <a href="{{ route('tickets.show', $t['id']) }}" wire:navigate class="rounded border border-border px-1.5 py-0.5 text-[11px] tabular-nums text-accent-content hover:underline">{{ $t['number'] }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Start-here list: live tickets from the top recurring category --}}
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-fg-muted">
                    Start here — open {{ $tally['categories'][0]['label'] ?? '' }}
                </h3>
                <div class="mt-2 space-y-1.5">
                    @forelse ($nextUp as $ticket)
                        <a href="{{ route('tickets.show', $ticket['id']) }}" wire:navigate
                           class="flex items-center justify-between gap-2 rounded-lg border border-border px-2.5 py-2 text-sm transition hover:border-accent/40 hover:shadow-sm">
                            <span class="min-w-0">
                                <span class="font-medium text-accent-content">{{ $ticket['number'] }}</span>
                                <span class="block truncate text-fg-muted">{{ $ticket['subject'] }}</span>
                            </span>
                            <flux:badge size="sm" :color="match ($ticket['status']) { \App\Enums\TicketStatus::Pending => 'amber', \App\Enums\TicketStatus::InProgress => 'blue', default => 'purple' }">{{ $ticket['status']->label() }}</flux:badge>
                        </a>
                    @empty
                        <p class="text-sm text-fg-subtle">Nothing left open in your top category. Nice.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
