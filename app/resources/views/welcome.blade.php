<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-fg antialiased">
        <div class="flex min-h-screen flex-col">
            {{-- Top bar --}}
            <header class="mx-auto flex w-full max-w-6xl items-center gap-3 px-6 py-5">
                <x-app-logo class="size-8" />
                <div class="flex-1"></div>
                <x-theme-toggle />
                @if (Route::has('login'))
                    @auth
                        <flux:button :href="route('dashboard')" wire:navigate variant="primary" size="sm">Dashboard</flux:button>
                    @else
                        <a href="{{ route('login') }}" class="text-sm font-medium text-fg-muted hover:text-fg" wire:navigate>Log in</a>
                        @if (Route::has('register'))
                            <flux:button :href="route('register')" wire:navigate variant="subtle" size="sm">Get started</flux:button>
                        @endif
                    @endauth
                @endif
            </header>

            {{-- Hero --}}
            <main class="mx-auto flex w-full max-w-6xl flex-1 flex-col items-center px-6">
                <div class="flex flex-col items-center gap-5 py-16 text-center sm:py-24">
                    <span class="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-fg-muted">
                        <span class="size-1.5 rounded-full bg-accent"></span>
                        Helpdesk for every company you support
                    </span>

                    <h1 class="max-w-3xl text-balance text-4xl font-bold tracking-tight sm:text-5xl md:text-6xl">
                        IT support, organized and <span class="text-accent-content">visible</span>.
                    </h1>

                    <p class="max-w-xl text-pretty text-base text-fg-muted sm:text-lg">
                        Capture requests from WhatsApp or the web, route them to the right technician,
                        and keep every company in the loop — with daily and weekly monitoring built in.
                    </p>

                    <div class="mt-2 flex w-full flex-col items-center justify-center gap-3 sm:w-auto sm:flex-row">
                        <flux:button :href="route('public.submit-ticket')" variant="primary" class="w-full justify-center sm:w-auto tap">
                            Submit an IT request
                        </flux:button>
                        @auth
                            <flux:button :href="route('dashboard')" wire:navigate variant="subtle" class="w-full justify-center sm:w-auto tap">
                                Open dashboard
                            </flux:button>
                        @else
                            <flux:button :href="route('login')" variant="subtle" class="w-full justify-center sm:w-auto tap">
                                Staff sign in
                            </flux:button>
                        @endauth
                    </div>
                </div>

                {{-- Feature strip --}}
                <div class="grid w-full max-w-5xl gap-4 pb-20 sm:grid-cols-3">
                    <div class="card p-5">
                        <div class="mb-3 flex size-10 items-center justify-center rounded-lg bg-accent/10 text-accent-content">
                            <flux:icon.ticket class="size-5" />
                        </div>
                        <h2 class="text-sm font-semibold">Two intake channels</h2>
                        <p class="mt-1 text-sm text-fg-muted">Requests arrive via WhatsApp or a no-login web form and land in one queue.</p>
                    </div>
                    <div class="card p-5">
                        <div class="mb-3 flex size-10 items-center justify-center rounded-lg bg-accent/10 text-accent-content">
                            <flux:icon.building-office-2 class="size-5" />
                        </div>
                        <h2 class="text-sm font-semibold">Multi-company</h2>
                        <p class="mt-1 text-sm text-fg-muted">Every ticket is tagged to its company for filtering, routing and reporting.</p>
                    </div>
                    <div class="card p-5">
                        <div class="mb-3 flex size-10 items-center justify-center rounded-lg bg-accent/10 text-accent-content">
                            <flux:icon.bell class="size-5" />
                        </div>
                        <h2 class="text-sm font-semibold">Proactive alerts</h2>
                        <p class="mt-1 text-sm text-fg-muted">A daily 5 PM pending digest and a Friday weekly summary keep IT on top of it.</p>
                    </div>
                </div>
            </main>

            <footer class="border-t border-border py-6 text-center text-xs text-fg-subtle">
                {{ config('app.name', 'IT Ticketing') }} &copy; {{ date('Y') }}
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
