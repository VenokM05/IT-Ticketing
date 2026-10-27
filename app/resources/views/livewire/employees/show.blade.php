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

        $this->employee = $employee->load(['department', 'company', 'tickets' => fn ($q) => $q->with('assignee')->latest()]);
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

<div class="flex flex-col gap-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">{{ $employee->name }}</h1>
            <flux:badge :color="$employee->is_active ? 'green' : 'zinc'" size="sm">{{ $employee->is_active ? 'Active' : 'Inactive' }}</flux:badge>
        </div>
        <flux:button :href="route('employees')" wire:navigate variant="subtle" icon="arrow-left">Back to directory</flux:button>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card p-4">
            <h2 class="text-sm font-semibold">Profile</h2>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-fg-muted">WhatsApp</dt><dd>{{ $employee->whatsapp_number }}</dd></div>
                <div class="flex justify-between"><dt class="text-fg-muted">Company</dt><dd>{{ $employee->company?->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-fg-muted">Department</dt><dd>{{ $employee->department?->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-fg-muted">Position</dt><dd>{{ $employee->position ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-fg-muted">Location</dt><dd>{{ $employee->location ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-fg-muted">Email</dt><dd>{{ $employee->email ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-fg-muted">Registered</dt><dd>{{ $employee->created_at->format('Y-m-d') }}</dd></div>
                <div class="flex justify-between"><dt class="text-fg-muted">Last message</dt><dd>{{ $lastMessageAt ? \Illuminate\Support\Carbon::parse($lastMessageAt)->format('Y-m-d H:i') : '—' }}</dd></div>
            </dl>
        </div>

        <div class="card lg:col-span-2">
            <div class="border-b border-border px-4 py-3">
                <h2 class="text-sm font-semibold">Ticket history ({{ $employee->tickets->count() }})</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-surface-muted text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium text-fg-muted">Ticket</th>
                            <th class="px-4 py-2 font-medium text-fg-muted">Status</th>
                            <th class="px-4 py-2 font-medium text-fg-muted">Priority</th>
                            <th class="px-4 py-2 font-medium text-fg-muted">Technician</th>
                            <th class="px-4 py-2 font-medium text-fg-muted">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($employee->tickets as $ticket)
                            <tr>
                                <td class="px-4 py-2">
                                    <a href="{{ route('tickets.show', $ticket) }}" wire:navigate class="font-medium text-accent-content hover:underline">{{ $ticket->ticket_number }}</a>
                                    <div class="text-fg-muted">{{ $ticket->subject }}</div>
                                </td>
                                <td class="px-4 py-2"><flux:badge :color="match ($ticket->status) { \App\Enums\TicketStatus::Closed => 'zinc', \App\Enums\TicketStatus::Resolved => 'green', \App\Enums\TicketStatus::InProgress => 'blue', \App\Enums\TicketStatus::Pending => 'amber', default => 'purple' }" size="sm">{{ $ticket->status->label() }}</flux:badge></td>
                                <td class="px-4 py-2">{{ $ticket->priority->label() }}</td>
                                <td class="px-4 py-2">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
                                <td class="px-4 py-2 whitespace-nowrap text-fg-muted">{{ $ticket->created_at->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-fg-muted">No tickets for this employee yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
