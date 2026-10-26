<?php

use App\Enums\TicketStatus;
use App\Models\DashboardNotification;
use App\Models\Ticket;
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
