<?php

use App\Models\User;

// Regression guard for the UI/UX redesign: light mode must be the served
// default, the Flux appearance manager + first-visit seed must be present,
// and the header theme toggle must render on every shell.

it('serves light mode by default with no hard-coded dark class', function (string $path) {
    $this->get($path)
        ->assertOk()
        ->assertDontSee('<html class="dark"', false)
        ->assertSee('flux.appearance.seeded', false)
        ->assertSee('applyAppearance', false);
})->with(['/', '/login', '/register', '/forgot-password', '/submit-ticket']);

it('renders the theme toggle inside the authenticated shell', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('<html class="dark"', false)
        ->assertSee('Switch to dark mode', false)
        ->assertSee('Switch to light mode', false);
});

it('renders every primary route over http for an admin', function (string $path) {
    $this->actingAs(User::factory()->admin()->create())->get($path)->assertOk();
})->with([
    '/dashboard', '/tickets', '/tickets/create', '/notifications',
    '/employees', '/companies', '/relinks', '/reports',
    '/settings/profile', '/settings/appearance',
]);

it('ships the design-token layer in the compiled css', function () {
    $built = glob(public_path('build/assets/app*.css'));
    $css = implode("\n", array_map(file_get_contents(...), $built));

    expect($built)->not->toBeEmpty()
        ->and($css)->toContain('--color-canvas')
        ->and($css)->toContain('--color-accent')
        ->and($css)->toContain('.card{')
        ->and($css)->toContain('.text-fg-muted');
})->skip(fn () => ! glob(public_path('build/assets/app*.css')), 'built assets missing');
