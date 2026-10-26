<?php

use App\Models\DashboardNotification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] #[Title('Notifications')] class extends Component {
    use WithPagination;

    public string $filter = 'unread';

    public function markRead(int $notificationId): void
    {
        // Per-user read state: only ever touches the current user's row (§7.8).
        DashboardNotification::query()
            ->whereKey($notificationId)
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->dispatch('notification-refresh');
    }

    public function markAllRead(): void
    {
        DashboardNotification::query()
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->dispatch('notification-refresh');
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'notifications' => DashboardNotification::query()
                ->with('ticket')
                ->where('user_id', auth()->id())
                ->when($this->filter === 'unread', fn ($q) => $q->whereNull('read_at'))
                ->when($this->filter === 'read', fn ($q) => $q->whereNotNull('read_at'))
                ->latest()
                ->paginate(20),
            'unreadCount' => DashboardNotification::query()
                ->where('user_id', auth()->id())
                ->whereNull('read_at')
                ->count(),
        ];
    }
}; ?>

<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Notifications</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $unreadCount }} unread</p>
        </div>
        <flux:button wire:click="markAllRead" variant="subtle" :disabled="$unreadCount === 0">Mark all read</flux:button>
    </div>

    <div class="flex gap-2">
        @foreach (['unread' => 'Unread', 'read' => 'Read', 'all' => 'All'] as $value => $label)
            <flux:button size="sm" :variant="$filter === $value ? 'primary' : 'subtle'" wire:click="$set('filter', '{{ $value }}')">{{ $label }}</flux:button>
        @endforeach
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($notifications as $notification)
                <li class="flex items-start justify-between gap-4 px-4 py-3 {{ $notification->isUnread() ? 'bg-blue-50/50 dark:bg-blue-950/30' : '' }}">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            @if ($notification->isUnread())
                                <span class="size-2 shrink-0 rounded-full bg-blue-500"></span>
                            @endif
                            @if ($notification->ticket)
                                <a href="{{ route('tickets.show', $notification->ticket) }}" wire:navigate class="truncate text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                                    {{ $notification->title }}
                                </a>
                            @else
                                <span class="truncate text-sm font-medium">{{ $notification->title }}</span>
                            @endif
                        </div>
                        @if ($notification->message)
                            <p class="mt-0.5 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $notification->message }}</p>
                        @endif
                        <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                            {{ $notification->type->value }} · {{ $notification->created_at->diffForHumans() }}
                        </p>
                    </div>
                    @if ($notification->isUnread())
                        <flux:button size="sm" variant="subtle" wire:click="markRead({{ $notification->id }})">Mark read</flux:button>
                    @else
                        <span class="text-xs text-zinc-400 dark:text-zinc-500">read {{ $notification->read_at?->diffForHumans() }}</span>
                    @endif
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Nothing here.</li>
            @endforelse
        </ul>

        @if ($notifications->hasPages())
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                {{ $notifications->links() }}
            </div>
        @endif
    </div>
</div>
