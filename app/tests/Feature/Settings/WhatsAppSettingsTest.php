<?php

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\WhatsAppSettings;
use Livewire\Volt\Volt;

/*
 * Settings › WhatsApp: administrator-managed API key. Stored encrypted, masked in
 * the UI, and resolved DB-first so outbound sends and webhook checks pick it up
 * immediately, overriding the env-backed config.
 */

function waAdmin(): User
{
    return User::factory()->admin()->create();
}

it('lets an administrator open the WhatsApp settings page', function () {
    $this->actingAs(waAdmin())
        ->get(route('settings.whatsapp'))
        ->assertOk()
        ->assertSee('API Key')
        ->assertSee('WhatsApp');
});

it('forbids technicians and redirects guests from WhatsApp settings', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get(route('settings.whatsapp'))
        ->assertForbidden();

    Illuminate\Support\Facades\Auth::guard('web')->forgetUser();
    $this->get(route('settings.whatsapp'))->assertRedirect(route('login'));
});

it('requires a non-empty API key before saving', function () {
    $this->actingAs(waAdmin());

    Volt::test('settings.whatsapp')
        ->set('apiKey', '')
        ->call('save')
        ->assertHasErrors(['apiKey']);

    expect(SystemSetting::query()->count())->toBe(0);
});

it('stores the API key trimmed and encrypted and masks it in the UI', function () {
    $this->actingAs(waAdmin());

    Volt::test('settings.whatsapp')
        ->set('apiKey', '  EAQAYJ1234ABCDz987  ')
        ->call('save')
        ->assertHasNoErrors();

    $row = SystemSetting::query()->where('key', 'whatsapp.access_token')->sole();

    // Encrypted at rest: the raw column must not contain the secret...
    $raw = Illuminate\Support\Facades\DB::table('system_settings')
        ->where('key', 'whatsapp.access_token')->value('value');
    expect($raw)->not->toContain('EAQAYJ1234')
        // ...while the model cast decrypts it, trimmed on the way in.
        ->and($row->value)->toBe('EAQAYJ1234ABCDz987');

    // Display shows only the masked tail, never the full key.
    Volt::test('settings.whatsapp')
        ->assertSee('****z987')
        ->assertDontSee('EAQAYJ1234ABCDz987', false);
});

it('makes the stored key take effect immediately over the env config', function () {
    config(['services.whatsapp.access_token' => 'env-token-abc123']);

    expect(WhatsAppSettings::accessToken())->toBe('env-token-abc123');

    WhatsAppSettings::setAccessToken('admin-token-xyz789');

    expect(WhatsAppSettings::accessToken())->toBe('admin-token-xyz789')
        ->and(WhatsAppSettings::credential('access_token'))->toBe('admin-token-xyz789');

    // Removing it falls back to the env value again.
    WhatsAppSettings::clearAccessToken();

    expect(WhatsAppSettings::accessToken())->toBe('env-token-abc123')
        ->and(WhatsAppSettings::hasStoredAccessToken())->toBeFalse();
});

it('sends outbound messages with the admin-stored token', function () {
    $this->actingAs(waAdmin());

    config([
        'services.whatsapp.enabled' => true,
        'services.whatsapp.access_token' => 'env-token',
        'services.whatsapp.phone_number_id' => '123456789',
        'services.whatsapp.base_url' => 'https://graph.facebook.com',
    ]);

    WhatsAppSettings::setAccessToken('admin-token-9999');

    Illuminate\Support\Facades\Http::fake(['graph.facebook.com/*' => Illuminate\Support\Facades\Http::response([
        'messages' => [['id' => 'wamid.TEST']],
    ], 200)]);

    app(WhatsAppCloudApiProbe::class)->probe();

    Illuminate\Support\Facades\Http::assertSent(
        fn ($request) => $request->hasHeader('Authorization', 'Bearer admin-token-9999'),
    );
});

it('removes the stored key through the UI action', function () {
    $this->actingAs(waAdmin());

    WhatsAppSettings::setAccessToken('to-be-removed');

    Volt::test('settings.whatsapp')
        ->call('remove');

    expect(SystemSetting::query()->where('key', 'whatsapp.access_token')->count())->toBe(0)
        ->and(WhatsAppSettings::hasStoredAccessToken())->toBeFalse();
});

/** Resolves the credential the same way WhatsAppCloudApi does, for the send assertion. */
class WhatsAppCloudApiProbe extends App\Services\WhatsAppCloudApi
{
    public function probe(): void
    {
        $this->sendText('639000000000', 'ping');
    }
}
