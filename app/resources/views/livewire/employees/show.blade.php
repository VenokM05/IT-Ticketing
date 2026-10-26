<?php

use App\Models\Employee;
use App\Models\TicketMessage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] #[Title('Employee')] class extends Component {
    public Employee $employee;

    public function mount(Employee $employee): void
    {
        $this->authorize('view', $employee);

        $this->employee = $employee->load(['department', 'tickets' => fn ($q) => $q->with('assignee')->latest()]);
    }

    public function with(): array
    {
        $ticketIds = $this->employee->tickets->pluck('id');

        return [
            'lastMessageAt' => $ticketIds->isEmpty()
                ? null
                : TicketMessage::query()->whereIn('ticket_id', $ticketIds)->max('created_at'),
        ];
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold">{{ $employee->name }}</h1>
            <flux:badge :color="$employee->is_active ? 'green' : 'zinc'" size="sm">{{ $employee->is_active ? 'Active' : 'Inactive' }}</flux:badge>
        </div>
        <flux:button :href="route('employees')" wire:navigate variant="subtle" icon="arrow-left">Back to directory</flux:button>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold">Profile</h2>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">WhatsApp</dt><dd>{{ $employee->whatsapp_number }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Department</dt><dd>{{ $employee->department?->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Location</dt><dd>{{ $employee->location ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Email</dt><dd>{{ $employee->email ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Registered</dt><dd>{{ $employee->created_at->format('Y-m-d') }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500 dark:text-zinc-400">Last message</dt><dd>{{ $lastMessageAt ? \Illuminate\Support\Carbon::parse($lastMessageAt)->format('Y-m-d H:i') : '—' }}</dd></div>
            </dl>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <h2 class="text-sm font-semibold">Ticket history ({{ $employee->tickets->count() }})</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-left dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Ticket</th>
                            <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Status</th>
                            <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Priority</th>
                            <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Technician</th>
                            <th class="px-4 py-2 font-medium text-zinc-500 dark:text-zinc-400">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($employee->tickets as $ticket)
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('tickets.show', $ticket) }}" wire:navigate class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ $ticket->ticket_number }}</a>
                                    <div class="text-zinc-500 dark:text-zinc-400">{{ $ticket->subject }}</div>
                                </td>
                                <td class="px-4 py-2"><flux:badge :color="match ($ticket->status) { \App\Enums\TicketStatus::Closed => 'zinc', \App\Enums\TicketStatus::Resolved => 'green', \App\Enums\TicketStatus::InProgress => 'blue', \App\Enums\TicketStatus::Pending => 'amber', default => 'purple' }" size="sm">{{ $ticket->status->label() }}</flux:badge></td>
                                <td class="px-4 py-2">{{ $ticket->priority->label() }}</td>
                                <td class="px-4 py-2">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
                                <td class="px-4 py-2 whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $ticket->created_at->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">No tickets for this employee yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
