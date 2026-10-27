<a
    {{ $attributes->merge(['class' => 'underline text-sm font-medium decoration-accent/40 underline-offset-2 duration-200 ease-out hover:decoration-accent text-accent-content']) }}
    wire:navigate
>
    {{ $slot }}
</a>
