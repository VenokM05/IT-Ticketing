@props(['title', 'subtitle' => null])

{{-- Consistent top-of-page masthead: title, optional subtitle, and an actions slot. --}}
<div {{ $attributes->class('flex flex-wrap items-end justify-between gap-x-4 gap-y-3') }}>
    <div class="min-w-0">
        <h1 class="truncate text-2xl font-semibold tracking-tight text-fg">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-fg-muted">{{ $subtitle }}</p>
        @endif
    </div>

    @if (isset($actions))
        <div class="flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endif
</div>
