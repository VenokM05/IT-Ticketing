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
            ->with(['assignee', 'employee'])
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
                'employee' => $ticket->employee?->name,
                'created_at' => $ticket->created_at,
            ])
            ->all();
    }
}; ?>

<div class="flex flex-col gap-4" wire:poll.7000ms>
    {{-- Status counters + unread indicator (PRD §10.1, FR-05) --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-7">
        @foreach ($this->counts() as $status => $count)
            <a
                href="{{ route('tickets', ['status' => $status]) }}"
                wire:navigate
                class="rounded-xl border border-zinc-200 bg-white p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
            >
                <div class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ \App\Enums\TicketStatus::from($status)->label() }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ $count }}</div>
            </a>
        @endforeach

        <a
            href="{{ route('notifications') }}"
            wire:navigate
            class="rounded-xl border border-zinc-200 bg-white p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
        >
            <div class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Unread</div>
            <div class="mt-1 text-2xl font-semibold {{ $this->unreadCount() > 0 ? 'text-red-600 dark:text-red-500' : '' }}">
                {{ $this->unreadCount() }}
            </div>
        </a>
    </div>

    {{-- Company monitoring strip: pending workload + this week per company (process.md 5 & 6) --}}
    @if (auth()->user()->isAdmin())
        <div class="grid gap-4 lg:grid-cols-3">
            <a
                href="{{ route('tickets', ['status' => 'pending']) }}"
                wire:navigate
                class="rounded-xl border p-4 {{ $this->pendingCount() > 0 ? 'border-amber-300 bg-amber-50 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:hover:bg-amber-950/60' : 'border-zinc-200 bg-white hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900' }}"
            >
                <div class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Awaiting IT action</div>
                <div class="mt-1 text-2xl font-semibold">{{ $this->pendingCount() }}</div>
                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Daily alert fires at {{ config('alerts.pending.at') }} {{ config('alerts.timezone') }}
                </div>
            </a>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">This week by company</h2>
                    <flux:button size="sm" variant="subtle" :href="route('reports')" wire:navigate>Full report</flux:button>
                </div>
                <table class="mt-2 w-full text-sm">
                    <thead class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                        <tr><th class="pb-1 font-medium">Company</th><th class="pb-1 text-right font-medium">Created</th><th class="pb-1 text-right font-medium">Still open</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->companyBreakdown() as $row)
                            <tr>
                                <td class="py-1.5">{{ $row['company'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['created'] }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ $row['open'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-2 text-zinc-500 dark:text-zinc-400">No ticket requests this week.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Latest tickets (§10.1) --}}
    <div class="relative overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <h2 class="text-sm font-semibold">Latest tickets</h2>
            <flux:button size="sm" variant="subtle" :href="route('tickets')" wire:navigate>View all</flux:button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Ticket</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Employee</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Priority</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Technician</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->latestTickets() as $ticket)
                        <tr>
                            <td class="px-4 py-2">
                                <a href="{{ route('tickets.show', $ticket['id']) }}" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">
                                    {{ $ticket['number'] }}
                                </a>
                                <div class="text-zinc-500 dark:text-zinc-400">{{ $ticket['subject'] }}</div>
                            </td>
                            <td class="px-4 py-2">{{ $ticket['employee'] ?? '—' }}</td>
                            <td class="px-4 py-2"><flux:badge :color="$ticket['priority']->value === 'critical' ? 'red' : ($ticket['priority']->value === 'high' ? 'orange' : 'zinc')" size="sm" class="capitalize">{{ $ticket['priority']->label() }}</flux:badge></td>
                            <td class="px-4 py-2"><flux:badge :color="match ($ticket['status']) { \App\Enums\TicketStatus::Closed => 'zinc', \App\Enums\TicketStatus::Resolved => 'green', \App\Enums\TicketStatus::InProgress => 'blue', \App\Enums\TicketStatus::Pending => 'amber', default => 'purple' }" size="sm">{{ $ticket['status']->label() }}</flux:badge></td>
                            <td class="px-4 py-2">{{ $ticket['assignee'] ?? 'Unassigned' }}</td>
                            <td class="px-4 py-2 whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $ticket['created_at']->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No tickets yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
