<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? '' }}{{ isset($title) ? ' · ' : '' }}{{ config('app.name', 'IT Ticketing') }}</title>

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

{{--
    Default the theme to Light for first-time visitors without clobbering an
    explicit choice later. Flux stores the selected scheme under
    localStorage['flux.appearance'] and clears that key when the user picks
    "System", so we guard the seed with a one-time 'seeded' marker: the first
    ever visit writes 'light', and any later preference (including System) is
    left untouched. This must run before @fluxAppearance so it reads the seed.
--}}
<script>
    (function () {
        try {
            if (!localStorage.getItem('flux.appearance.seeded')) {
                if (localStorage.getItem('flux.appearance') === null) {
                    localStorage.setItem('flux.appearance', 'light');
                }
                localStorage.setItem('flux.appearance.seeded', '1');
            }
        } catch (e) { /* private mode / storage disabled — fall back to light */ }
    })();
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
