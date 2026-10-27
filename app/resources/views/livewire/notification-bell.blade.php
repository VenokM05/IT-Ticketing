<?php

use App\Models\DashboardNotification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    #[On('notification-refresh')]
    public function refreshed(): void
    {
        // Re-render on demand; polling and in-app actions both land here.
    }

    #[Computed]
    public function unreadCount(): int
    {
        return DashboardNotification::query()
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->count();
    }

    #[Computed]
    public function recent(): array
    {
        return DashboardNotification::query()
            ->with('ticket')
            ->where('user_id', auth()->id())
            ->latest()
            ->limit(6)
            ->get()
            ->all();
    }

    public function markRead(int $notificationId): void
    {
        DashboardNotification::query()
            ->whereKey($notificationId)
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        DashboardNotification::query()
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}; ?>

<div wire:poll.10000ms>
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="subtle" size="sm" class="relative" aria-label="Notifications">
            <flux:icon.bell class="size-5" />
            @if ($this->unreadCount() > 0)
                <span wire:ignore.self class="absolute -top-1 -right-1 flex min-w-5 h-5 items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-semibold text-white">
                    {{ min($this->unreadCount(), 99) }}
                </span>
            @endif
        </flux:button>

        <flux:menu class="w-80">
            <div class="flex items-center justify-between px-1 py-1.5">
                <span class="text-sm font-semibold">Notifications</span>
                @if ($this->unreadCount() > 0)
                    <button wire:click="markAllRead" class="text-xs text-accent-content hover:underline">Mark all read</button>
                @endif
            </div>
            <flux:menu.separator />

            @forelse ($this->recent() as $notification)
                <div class="flex items-start gap-2 px-1 py-1.5">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $notification->isUnread() ? 'bg-accent' : 'bg-border' }}"></span>
                    <div class="min-w-0 flex-1">
                        @if ($notification->ticket)
                            <a href="{{ route('tickets.show', $notification->ticket) }}" wire:navigate class="block truncate text-sm {{ $notification->isUnread() ? 'font-medium' : 'text-fg-muted' }}">
                                {{ $notification->title }}
                            </a>
                        @else
                            <span class="block truncate text-sm {{ $notification->isUnread() ? 'font-medium' : 'text-fg-muted' }}">{{ $notification->title }}</span>
                        @endif
                        <span class="block text-xs text-fg-subtle">{{ $notification->created_at->diffForHumans() }}</span>
                    </div>
                    @if ($notification->isUnread())
                        <button wire:click="markRead({{ $notification->id }})" class="text-xs text-accent-content hover:underline">Read</button>
                    @endif
                </div>
            @empty
                <div class="px-1 py-3 text-center text-sm text-fg-muted">No notifications yet.</div>
            @endforelse

            <flux:menu.separator />
            <a href="{{ route('notifications') }}" wire:navigate class="block px-1 py-1.5 text-center text-sm text-accent-content hover:underline">
                View all notifications
            </a>
        </flux:menu>
    </flux:dropdown>
</div>
