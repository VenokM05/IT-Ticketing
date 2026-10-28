@php $logo = \App\Services\AppSettings::logo(); @endphp
<div class="flex items-center gap-2.5">
    @if ($logo)
        <img src="{{ route('branding.logo') }}" alt="{{ config('app.name', 'IT Ticketing') }}" class="size-8 shrink-0 rounded-lg object-contain" />
    @else
        <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground shadow-sm">
            <x-app-logo-icon class="size-5" />
        </div>
    @endif
    <span class="truncate text-sm font-semibold leading-none">{{ config('app.name', 'IT Ticketing') }}</span>
</div>
