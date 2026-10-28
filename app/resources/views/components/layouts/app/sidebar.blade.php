<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-fg antialiased">
        <flux:sidebar stashable sticky class="border-r border-border bg-surface">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="px-1 py-1" wire:navigate>
                <x-app-logo class="size-8" />
            </a>

            @if (auth()->user()?->isAdmin())
                <flux:button :href="route('tickets.create')" wire:navigate variant="primary" icon="plus" class="mt-3 justify-center">
                    New request
                </flux:button>
            @endif

            <flux:navlist variant="outline" class="mt-2">
                <flux:navlist.group heading="Workspace" class="grid">
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>Dashboard</flux:navlist.item>
                    <flux:navlist.item icon="ticket" :href="route('tickets')" :current="request()->routeIs('tickets*')" wire:navigate>Tickets</flux:navlist.item>
                    <flux:navlist.item icon="bell" :href="route('notifications')" :current="request()->routeIs('notifications')" wire:navigate>Notifications</flux:navlist.item>
                </flux:navlist.group>

                @if (auth()->user()?->isAdmin())
                    <flux:navlist.group heading="Administration" class="mt-2 grid">
                        <flux:navlist.item icon="user-group" :href="route('employees')" :current="request()->routeIs('employees*')" wire:navigate>Employees</flux:navlist.item>
                        <flux:navlist.item icon="building-office-2" :href="route('companies')" :current="request()->routeIs('companies*')" wire:navigate>Companies</flux:navlist.item>
                        <flux:navlist.item icon="wrench-screwdriver" :href="route('staff')" :current="request()->routeIs('staff*')" wire:navigate>Staff</flux:navlist.item>
                        <flux:navlist.item icon="identification" :href="route('relinks')" :current="request()->routeIs('relinks')" wire:navigate>Approvals</flux:navlist.item>
                        <flux:navlist.item icon="chart-bar" :href="route('reports')" :current="request()->routeIs('reports*')" wire:navigate>Reports</flux:navlist.item>
                        <flux:navlist.item icon="megaphone" :href="route('broadcast')" :current="request()->routeIs('broadcast*')" wire:navigate>Broadcast</flux:navlist.item>
                        <flux:navlist.item icon="clipboard-document-list" :href="route('audit')" :current="request()->routeIs('audit*')" wire:navigate>Audit</flux:navlist.item>
                    </flux:navlist.group>
                @endif
            </flux:navlist>
        </flux:sidebar>

        {{-- Global top bar: Notifications, Account, Theme toggle (always visible, right-aligned) --}}
        <flux:header class="border-b border-border bg-surface">
            <flux:sidebar.toggle icon="bars-2" inset="left" class="lg:hidden" />

            <a href="{{ route('dashboard') }}" class="ml-1 flex items-center gap-2 lg:hidden" wire:navigate>
                <x-app-logo-icon class="size-7 text-accent" />
                <span class="font-semibold">{{ config('app.name', 'IT Ticketing') }}</span>
            </a>

            <flux:spacer />

            {{-- Right cluster, ordered right-to-left: Notifications, Account/Profile, Theme toggle --}}
            <x-theme-toggle />

            <flux:dropdown position="bottom" align="end" class="ml-1">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                />

                <flux:menu class="w-[240px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <span class="relative flex h-9 w-9 shrink-0 overflow-hidden rounded-lg">
                                    <span class="flex h-full w-full items-center justify-center rounded-lg bg-accent text-accent-foreground text-sm font-semibold">
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>
                                <div class="grid flex-1 text-left text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs text-fg-subtle">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>Settings</flux:menu.item>
                    <flux:menu.item href="/settings/appearance" icon="sun" wire:navigate>Appearance</flux:menu.item>
                    @if (auth()->user()->isAdmin())
                        <flux:menu.item href="{{ route('settings.branding') }}" icon="swatch" wire:navigate>Branding</flux:menu.item>
                        <flux:menu.item href="{{ route('settings.whatsapp') }}" icon="chat-bubble-left-right" wire:navigate>WhatsApp</flux:menu.item>
                        <flux:menu.item href="{{ route('settings.canned-responses') }}" icon="clipboard-document-list" wire:navigate>Canned Responses</flux:menu.item>
                        <flux:menu.item href="{{ route('settings.categories') }}" icon="tag" wire:navigate>Categories</flux:menu.item>
                        <flux:menu.item href="{{ route('settings.priorities') }}" icon="signal" wire:navigate>Priorities</flux:menu.item>
                        <flux:menu.item href="{{ route('settings.knowledge-base') }}" icon="book-open" wire:navigate>Knowledge Base</flux:menu.item>
                    @endif

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>

            <div class="ml-1"><livewire:notification-bell /></div>
        </flux:header>

        {{ $slot }}

        @fluxScripts
    </body>
</html>
