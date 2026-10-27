<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-fg antialiased">
        {{-- Theme is reachable even before signing in. --}}
        <div class="fixed right-4 top-4 z-10 rounded-xl border border-border bg-surface/80 p-1 backdrop-blur">
            <x-theme-toggle />
        </div>

        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="flex items-center justify-center">
                        <x-app-logo class="size-8" />
                    </span>
                </a>

                <div class="card w-full p-6 shadow-sm sm:p-8">
                    {{ $slot }}
                </div>

                <p class="text-center text-xs text-fg-subtle">
                    {{ config('app.name', 'IT Ticketing') }} &middot; Secure employee &amp; vendor support
                </p>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
