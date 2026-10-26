<?php

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] #[Title('Tickets')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $priority = '';
    public string $category = '';
    public string $technician = '';
    public string $from = '';
    public string $to = '';

    public function updated($property): void
    {
        $this->resetPage();
    }

    public function clear(): void
    {
        $this->reset(['search', 'status', 'priority', 'category', 'technician', 'from', 'to']);
        $this->resetPage();
    }

    /**
     * Listing is visible to every active user; rows are scoped by role (FR-13).
     */
    public function with(): array
    {
        $user = auth()->user();
        $isAdmin = $user->isAdmin();

        $tickets = Ticket::query()
            ->with(['assignee', 'employee'])
            ->when(! $isAdmin, fn (Builder $q) => $q->where('assigned_to', $user->id))
            ->when($this->search, function (Builder $q) use ($isAdmin) {
                $q->where(function (Builder $inner) use ($isAdmin) {
                    $inner->where('ticket_number', 'like', "%{$this->search}%")
                        ->orWhere('subject', 'like', "%{$this->search}%");

                    // Employee search is admin-only (§10.2 "where authorized").
                    if ($isAdmin) {
                        $inner->orWhereHas('employee', fn (Builder $e) => $e->where('name', 'like', "%{$this->search}%"));
                    }
                });
            })
            ->when($this->status, fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->priority, fn (Builder $q) => $q->where('priority', $this->priority))
            ->when($this->category, fn (Builder $q) => $q->where('category', $this->category))
            ->when($this->technician, fn (Builder $q) => $q->where('assigned_to', $this->technician))
            ->when($this->from, fn (Builder $q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn (Builder $q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest()
            ->paginate(15);

        return [
            'tickets' => $tickets,
            'technicians' => $isAdmin
                ? User::query()->where('role', UserRole::Technician->value)->orderBy('name')->get()
                : collect(),
        ];
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Tickets</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ auth()->user()->isAdmin() ? 'All tickets' : 'Your assigned tickets' }}
            </p>
        </div>
    </div>

    {{-- Search + filters (§10.2) --}}
    <div class="grid grid-cols-2 gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-3 lg:grid-cols-7">
        <div class="col-span-2 sm:col-span-1 lg:col-span-2">
            <flux:input wire:model.live.debounce.400ms="search" placeholder="Search number / subject…" icon="magnifying-glass" />
        </div>
        <flux:select wire:model.live="status" placeholder="All statuses">
            <flux:select.option value="">All statuses</flux:select.option>
            @foreach (TicketStatus::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="priority" placeholder="All priorities">
            <flux:select.option value="">All priorities</flux:select.option>
            @foreach (TicketPriority::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="category" placeholder="All categories">
            <flux:select.option value="">All categories</flux:select.option>
            @foreach (TicketCategory::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        @if (auth()->user()->isAdmin())
            <flux:select wire:model.live="technician" placeholder="Any technician">
                <flux:select.option value="">Any technician</flux:select.option>
                @foreach ($technicians as $tech)
                    <flux:select.option value="{{ $tech->id }}">{{ $tech->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:input type="date" wire:model.live="from" label="From" />
        <flux:input type="date" wire:model.live="to" label="To" />
        <flux:button wire:click="clear" variant="subtle">Clear</flux:button>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Ticket</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Employee</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Category</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Priority</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Technician</th>
                        <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td class="px-4 py-2">
                                <a href="{{ route('tickets.show', $ticket) }}" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">
                                    {{ $ticket->ticket_number }}
                                </a>
                                <div class="text-zinc-500 dark:text-zinc-400">{{ $ticket->subject }}</div>
                            </td>
                            <td class="px-4 py-2">{{ $ticket->employee?->name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $ticket->category->label() }}</td>
                            <td class="px-4 py-2"><flux:badge :color="match ($ticket->priority) { \App\Enums\TicketPriority::Critical => 'red', \App\Enums\TicketPriority::High => 'orange', default => 'zinc' }" size="sm">{{ $ticket->priority->label() }}</flux:badge></td>
                            <td class="px-4 py-2"><flux:badge :color="match ($ticket->status) { \App\Enums\TicketStatus::Closed => 'zinc', \App\Enums\TicketStatus::Resolved => 'green', \App\Enums\TicketStatus::InProgress => 'blue', \App\Enums\TicketStatus::Pending => 'amber', default => 'purple' }" size="sm">{{ $ticket->status->label() }}</flux:badge></td>
                            <td class="px-4 py-2">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
                            <td class="px-4 py-2 whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $ticket->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No tickets match your filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($tickets->hasPages())
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                {{ $tickets->links() }}
            </div>
        @endif
    </div>
</div>
