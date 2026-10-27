<?php

use App\Enums\TicketStatus;
use App\Models\DashboardNotification;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] #[Title('Dashboard')] class extends Component {
    /**
     * Status counters, scoped to the technician's own queue on non-admin roles (§3.3).
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $user = auth()->user();

        $rows = DB::table('tickets')
            ->when(
                ! $user->isAdmin(),
                fn ($q) => $q->where('assigned_to', $user->id)
            )
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $counts = [];
        foreach (TicketStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $counts;
    }

    public function unreadCount(): int
    {
        return DashboardNotification::query()
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Requests still awaiting IT action, using the same status set the daily
     * 5 PM alert uses (config/alerts.php) so the page and the alert agree.
     */
    public function pendingCount(): int
    {
        return Ticket::query()
            ->whereIn('status', (array) config('alerts.pending.statuses', []))
            ->count();
    }

    /**
     * Open tickets whose response or resolve SLA deadline has passed without the
     * milestone being met. Scopes to the technician's own queue for non-admins.
     */
    public function slaBreachedCount(): int
    {
        $user = auth()->user();
        $now = now();

        return Ticket::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('assigned_to', $user->id))
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
            ->where(function ($q) use ($now) {
                $q->where(fn ($r) => $r->whereNotNull('response_due_at')
                    ->whereNull('first_response_at')
                    ->where('response_due_at', '<', $now))
                    ->orWhere(fn ($r) => $r->whereNotNull('resolve_due_at')
                        ->whereNull('resolved_at')
                        ->where('resolve_due_at', '<', $now));
            })
            ->count();
    }

    /**
     * This week's volume per company — the Friday monitoring view at a glance.
     *
     * @return list<array{company: string, created: int, open: int}>
     */
    public function companyBreakdown(): array
    {
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        return Ticket::query()
            ->with('company:id,name')
            ->whereDate('created_at', '>=', $weekStart)
            ->get()
            ->groupBy(fn (Ticket $t) => $t->company?->name ?? 'Unassigned')
            ->map(fn ($group, string $name) => [
                'company' => $name,
                'created' => $group->count(),
                'open' => $group->filter(
                    fn (Ticket $t) => ! in_array($t->status, [TicketStatus::Resolved, TicketStatus::Closed], true),
                )->count(),
            ])
            ->sortByDesc('created')
            ->take(6)
            ->values()
            ->all();
    }

    public function latestTickets(): array
    {
        $user = auth()->user();

        return Ticket::query()
            ->with(['assignee', 'employee.department', 'company'])
            ->when(
                ! $user->isAdmin(),
                fn ($q) => $q->where('assigned_to', $user->id)
            )
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'assignee' => $ticket->assignee?->name,
                // Unified requester/company/department: enrollment first, public snapshot fallback.
                'requester' => $ticket->requesterName(),
                'company' => $ticket->companyLabel(),
                'department' => $ticket->requesterDepartment(),
                'created_at' => $ticket->created_at,
            ])
            ->all();
    }
}; ?>

<div class="flex flex-col gap-6" wire:poll.7000ms>
    {{-- Page masthead --}}
    <x-page.header title="Dashboard" :subtitle="'Welcome back, '.auth()->user()->name.'.'">
        <x-slot:actions>
            @if (auth()->user()->can('create', App\Models\Ticket::class))
                <flux:button :href="route('tickets.create')" variant="primary" icon="plus" wire:navigate>New request</flux:button>
            @endif
            <flux:button :href="route('tickets')" variant="subtle" icon="ticket" wire:navigate>All tickets</flux:button>
        </x-slot:actions>
    </x-page.header>

    {{-- Status counters + unread indicator (PRD §10.1, FR-05) --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-8">
        @foreach ($this->counts() as $status => $count)
            <a
                href="{{ route('tickets', ['status' => $status]) }}"
                wire:navigate
                class="card group p-4 transition hover:border-accent/40 hover:shadow-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-accent"
            >
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-fg-muted">{{ \App\Enums\TicketStatus::from($status)->label() }}</span>
                    <flux:icon.chevron-right class="size-4 text-fg-subtle opacity-0 transition group-hover:translate-x-0.5 group-hover:opacity-100" />
                </div>
                <div class="mt-2 text-2xl font-semibold tabular-nums">{{ $count }}</div>
            </a>
        @endforeach

        <a
            href="{{ route('notifications') }}"
            wire:navigate
            class="card p-4 transition hover:border-accent/40 hover:shadow-sm focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-accent"
        >
            <span class="text-sm font-medium text-fg-muted">Unread</span>
            <div class="mt-2 text-2xl font-semibold tabular-nums {{ $this->unreadCount() > 0 ? 'text-accent-content' : '' }}">
                {{ $this->unreadCount() }}
            </div>
        </a>
    </div>

    {{-- Company monitoring strip: pending workload + this week per company (process.md 5 & 6) --}}
    @if (auth()->user()->isAdmin())
        <div class="grid gap-4 lg:grid-cols-4">
            <a
                href="{{ route('tickets', ['status' => 'pending']) }}"
                wire:navigate
                class="card p-5 transition hover:shadow-sm {{ $this->pendingCount() > 0 ? '!border-amber-300 bg-amber-50/70 dark:!border-amber-800 dark:bg-amber-950/30' : '' }}"
            >
                <div class="flex items-center gap-2 text-sm font-medium text-fg-muted">
                    <flux:icon.bell class="size-4 {{ $this->pendingCount() > 0 ? 'text-amber-600 dark:text-amber-400' : '' }}" />
                    Awaiting IT action
                </div>
                <div class="mt-2 text-3xl font-semibold tabular-nums">{{ $this->pendingCount() }}</div>
                <div class="mt-1 text-xs text-fg-subtle">
                    Daily alert fires at {{ config('alerts.pending.at') }} {{ config('alerts.timezone') }}
                </div>
            </a>

            <a
                href="{{ route('tickets') }}"
                wire:navigate
                class="card p-5 transition hover:shadow-sm {{ $this->slaBreachedCount() > 0 ? '!border-red-300 bg-red-50/70 dark:!border-red-800 dark:bg-red-950/30' : '' }}"
            >
                <div class="flex items-center gap-2 text-sm font-medium text-fg-muted">
                    <flux:icon.clock class="size-4 {{ $this->slaBreachedCount() > 0 ? 'text-red-600 dark:text-red-400' : '' }}" />
                    SLA breached
                </div>
                <div class="mt-2 text-3xl font-semibold tabular-nums">{{ $this->slaBreachedCount() }}</div>
                <div class="mt-1 text-xs text-fg-subtle">Open tickets past their response/resolve target</div>
            </a>

            <div class="card p-5 lg:col-span-2">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">This week by company</h2>
                    <flux:button size="sm" variant="subtle" :href="route('reports')" wire:navigate>Full report</flux:button>
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-fg-subtle">
                            <tr><th class="pb-2 font-medium">Company</th><th class="pb-2 text-right font-medium">Created</th><th class="pb-2 text-right font-medium">Still open</th></tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse ($this->companyBreakdown() as $row)
                                <tr>
                                    <td class="py-2">{{ $row['company'] }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ $row['created'] }}</td>
                                    <td class="py-2 text-right tabular-nums text-fg-muted">{{ $row['open'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-3 text-fg-subtle">No ticket requests this week.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Latest tickets (§10.1) --}}
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between border-b border-border px-5 py-3.5">
            <h2 class="text-sm font-semibold">Latest tickets</h2>
            <flux:button size="sm" variant="subtle" :href="route('tickets')" wire:navigate>View all</flux:button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-muted text-left">
                    <tr>
                        <th class="px-5 py-2.5 font-medium text-fg-subtle">Ticket</th>
                        <th class="px-5 py-2.5 font-medium text-fg-subtle">Requester</th>
                        <th class="px-5 py-2.5 font-medium text-fg-subtle">Company</th>
                        <th class="px-5 py-2.5 font-medium text-fg-subtle">Department</th>
                        <th class="px-5 py-2.5 font-medium text-fg-subtle">Priority</th>
                        <th class="px-5 py-2.5 font-medium text-fg-subtle">Status</th>
                        <th class="px-5 py-2.5 font-medium text-fg-subtle">Technician</th>
                        <th class="px-5 py-2.5 font-medium text-fg-subtle">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->latestTickets() as $ticket)
                        <tr class="transition-colors hover:bg-surface-muted/60">
                            <td class="px-5 py-3">
                                <a href="{{ route('tickets.show', $ticket['id']) }}" wire:navigate class="font-medium text-accent-content hover:underline">
                                    {{ $ticket['number'] }}
                                </a>
                                <div class="text-fg-muted">{{ $ticket['subject'] }}</div>
                            </td>
                            <td class="px-5 py-3">{{ $ticket['requester'] ?? '—' }}</td>
                            <td class="px-5 py-3 text-fg-muted">{{ $ticket['company'] }}</td>
                            <td class="px-5 py-3 text-fg-muted">{{ $ticket['department'] ?? '—' }}</td>
                            <td class="px-5 py-3"><flux:badge :color="$ticket['priority']->value === 'critical' ? 'red' : ($ticket['priority']->value === 'high' ? 'orange' : 'zinc')" size="sm" class="capitalize">{{ $ticket['priority']->label() }}</flux:badge></td>
                            <td class="px-5 py-3"><flux:badge :color="match ($ticket['status']) { \App\Enums\TicketStatus::Closed => 'zinc', \App\Enums\TicketStatus::Resolved => 'green', \App\Enums\TicketStatus::InProgress => 'blue', \App\Enums\TicketStatus::Pending => 'amber', default => 'purple' }" size="sm">{{ $ticket['status']->label() }}</flux:badge></td>
                            <td class="px-5 py-3">{{ $ticket['assignee'] ?? 'Unassigned' }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-fg-subtle">{{ $ticket['created_at']->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-10 text-center text-fg-subtle">No tickets yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
