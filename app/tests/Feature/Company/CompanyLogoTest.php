<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

/*
 * Companies › per-company logo (Branding request). The image lands on the private
 * "local" disk keyed by company id, the table shows a thumbnail, and bytes are
 * streamed back through the public companies.logo route.
 */

// Same trick as BrandingTest: a real 1x1 PNG, because UploadedFile::fake()->image()
// needs the GD extension, which this XAMPP build does not have.
function company_logo_file(string $name = 'mark.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
}

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create();
});

it('stores an uploaded company logo and serves it through the route', function () {
    $this->actingAs($this->admin);

    Volt::test('companies.index')
        ->call('startCreate')
        ->set('fName', 'Globex Corporation')
        ->set('logo', company_logo_file('globex.png'))
        ->call('save')
        ->assertHasNoErrors();

    $company = Company::query()->where('name', 'Globex Corporation')->sole();

    expect($company->logo_path)->not->toBeNull()
        ->and($company->hasLogo())->toBeTrue();
    Storage::disk('local')->assertExists($company->logo_path);

    $this->get(route('companies.logo', $company))->assertOk();
});

it('rejects a non-image logo upload', function () {
    $this->actingAs($this->admin);

    Volt::test('companies.index')
        ->call('startCreate')
        ->set('fName', 'Initech')
        ->set('logo', UploadedFile::fake()->createWithContent('invoice.pdf', 'not a real image'))
        ->call('save')
        ->assertHasErrors(['logo']);

    expect(Company::query()->count())->toBe(0);
});

it('replaces the old logo file when a new one is uploaded', function () {
    $this->actingAs($this->admin);

    $company = Company::factory()->create();

    Volt::test('companies.index')
        ->call('startEdit', $company->id)
        ->set('logo', company_logo_file('one.png'))
        ->call('save')
        ->assertHasNoErrors();
    $first = $company->fresh()->logo_path;

    Volt::test('companies.index')
        ->call('startEdit', $company->id)
        ->set('logo', company_logo_file('two.png'))
        ->call('save')
        ->assertHasNoErrors();

    expect($company->fresh()->logo_path)->not->toBe($first);
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($company->fresh()->logo_path);
});

it('removes the logo when the checkbox is set', function () {
    $this->actingAs($this->admin);

    $company = Company::factory()->create();

    Volt::test('companies.index')
        ->call('startEdit', $company->id)
        ->set('logo', company_logo_file('to-remove.png'))
        ->call('save');
    $path = $company->fresh()->logo_path;

    Volt::test('companies.index')
        ->call('startEdit', $company->id)
        ->set('removeLogo', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($company->fresh()->logo_path)->toBeNull()
        ->and($company->fresh()->hasLogo())->toBeFalse();
    Storage::disk('local')->assertMissing($path);
});

it('shows the logo thumbnail in the companies table', function () {
    $company = Company::factory()->create(['name' => 'Stark Industries']);
    Storage::disk('local')->put($path = 'company-logos/'.$company->id.'/mark.png', 'bytes');
    $company->update(['logo_path' => $path]);

    $this->actingAs($this->admin);

    Volt::test('companies.index')
        ->assertSee('Stark Industries')
        ->assertSee(route('companies.logo', $company), false);
});

it('404s the company logo route when no logo exists', function () {
    $company = Company::factory()->create();

    $this->get(route('companies.logo', $company))->assertNotFound();
});
