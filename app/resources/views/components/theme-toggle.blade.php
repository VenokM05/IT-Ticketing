{{--
    Light / Dark toggle. Drives the same Flux appearance store the Settings ›
    Appearance page binds to ($flux.appearance), so the two never drift. A click
    flips explicitly between light and dark; "system" resolves through the OS
    media query so the icon always matches what the user actually sees.
--}}
@props(['iconClasses' => 'size-5'])

<button
    type="button"
    x-data="{
        get scheme() {
            let pref = $flux.appearance;
            if (pref === 'dark' || pref === 'light') return pref;
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        },
        toggle() { $flux.appearance = this.scheme === 'dark' ? 'light' : 'dark'; }
    }"
    x-on:click="toggle()"
    ::aria-label="scheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
    ::title="scheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
    {{ $attributes->class([
        'inline-flex size-10 items-center justify-center rounded-lg text-fg-muted',
        'transition-colors hover:bg-surface-muted hover:text-fg',
        'focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-accent',
    ]) }}>
    <flux:icon.sun data-slot="icon" class="{{ $iconClasses }}" x-cloak x-show="scheme === 'dark'" />
    <flux:icon.moon data-slot="icon" class="{{ $iconClasses }}" x-cloak x-show="scheme !== 'dark'" />
</button>
