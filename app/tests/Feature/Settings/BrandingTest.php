<?php

use App\Models\User;
use App\Services\AppSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

/*
 * Settings › Branding: an administrator-editable project title and global logo.
 * The title is stored DB-first and applied over config('app.name'); the logo
 * lives on the private "local" disk and is streamed through the public
 * branding.logo route.
 */

// A genuine 1x1 PNG written as raw bytes, so the "image" validation rule passes
// without relying on the GD extension (absent on this XAMPP build).
function branding_logo_file(string $name = 'logo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
}

function brandingAdmin(): User
{
    return User::factory()->admin()->create();
}

it('lets an administrator open the branding settings page', function () {
    $this->actingAs(brandingAdmin())
        ->get(route('settings.branding'))
        ->assertOk()
        ->assertSee('Project Title')
        ->assertSee('Branding');
});

it('forbids technicians and redirects guests from branding settings', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get(route('settings.branding'))
        ->assertForbidden();

    Auth::guard('web')->forgetUser();
    $this->get(route('settings.branding'))->assertRedirect(route('login'));
});

it('requires a non-empty project title before saving', function () {
    $this->actingAs(brandingAdmin());

    Volt::test('settings.branding')
        ->set('title', '')
        ->call('saveTitle')
        ->assertHasErrors(['title']);

    expect(AppSettings::title())->toBeNull();
});

it('stores the trimmed title and overrides the app name for this request', function () {
    $this->actingAs(brandingAdmin());

    Volt::test('settings.branding')
        ->set('title', '  Acme Helpdesk  ')
        ->call('saveTitle')
        ->assertHasNoErrors();

    expect(AppSettings::title())->toBe('Acme Helpdesk')
        // The in-request override is what makes the sidebar reflect it live.
        ->and(config('app.name'))->toBe('Acme Helpdesk');
});

it('stores an uploaded logo and returns it over the public route', function () {
    Storage::fake('local');
    $this->actingAs(brandingAdmin());

    Volt::test('settings.branding')
        ->set('logo', branding_logo_file())
        ->call('saveLogo')
        ->assertHasNoErrors();

    $path = AppSettings::logo();
    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);

    $this->get(route('branding.logo'))->assertOk();
});

it('rejects a non-image logo upload', function () {
    Storage::fake('local');
    $this->actingAs(brandingAdmin());

    Volt::test('settings.branding')
        ->set('logo', UploadedFile::fake()->createWithContent('notes.txt', 'not a real image'))
        ->call('saveLogo')
        ->assertHasErrors(['logo']);

    expect(AppSettings::logo())->toBeNull();
});

it('removes the logo and falls back to the built-in mark', function () {
    Storage::fake('local');
    $this->actingAs(brandingAdmin());

    Volt::test('settings.branding')
        ->set('logo', branding_logo_file())
        ->call('saveLogo');

    Volt::test('settings.branding')->call('removeLogo');

    expect(AppSettings::logo())->toBeNull();
    $this->get(route('branding.logo'))->assertNotFound();
});

it('404s the branding logo route when nothing has been set', function () {
    $this->get(route('branding.logo'))->assertNotFound();
});
