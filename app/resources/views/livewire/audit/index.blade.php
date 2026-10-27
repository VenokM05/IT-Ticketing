<?php

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Administrator audit trail (PRD Phase 8, Rec #10): who changed what across
 * WhatsApp credentials, companies and ticket classification/status edits.
 */
new #[Layout('components.layouts.app')] #[Title('Audit Trail')] class extends Component {
    use WithPagination;

    public string $event = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function updated($property): void
    {
        if ($property === 'event') {
            $this->resetPage();
        }
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function with(): array
    {
        return [
            'logs' => ActivityLog::query()
                ->with(['actor', 'subject'])
                ->when($this->event, fn (Builder $q) => $q->where('event', 'like', $this->event.'%'))
                ->latest('created_at')
                ->latest('id')
                ->paginate(25),
            'eventGroups' => ActivityLog::query()->distinct()->orderBy('event')->pluck('event'),
        ];
    }
}; ?>

<div class="flex flex-col gap-5">
    <x-page.header title="Audit Trail" subtitle="Configuration and classification changes, newest first" />

    <div class="card p-4">
        <flux:select wire:model.live="event" label="Filter by event" placeholder="All events">
            <option value="">All events</option>
            @foreach ($eventGroups as $group)
                <option value="{{ $group }}">{{ $group }}</option>
            @endforeach
        </flux:select>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-muted text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium text-fg-muted">When</th>
                        <th class="px-4 py-2 font-medium text-fg-muted">Actor</th>
                        <th class="px-4 py-2 font-medium text-fg-muted">Event</th>
                        <th class="px-4 py-2 font-medium text-fg-muted">Subject</th>
                        <th class="px-4 py-2 font-medium text-fg-muted">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2 text-fg-muted">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-2">{{ $log->actor?->name ?? 'System' }}</td>
                            <td class="px-4 py-2"><flux:badge size="sm" color="zinc">{{ $log->event }}</flux:badge></td>
                            <td class="px-4 py-2 text-fg-muted">
                                @if ($log->subject)
                                    {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-fg-muted">
                                @if ($log->properties)
                                    <span class="font-mono text-xs">{{ json_encode($log->properties) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-fg-muted">No audited activity yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="border-t border-border px-4 py-3">{{ $logs->links() }}</div>
        @endif
    </div>
</div>
