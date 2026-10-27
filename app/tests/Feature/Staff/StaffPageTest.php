<?php

use App\Enums\TicketCategory;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Livewire\Volt\Volt;

// The Staff page is the admin surface that configures technicians for auto-assign:
// home company, skills and on-call status.

it('forbids non-admins from opening the staff page', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get('/staff')
        ->assertForbidden();
});

it('lets an administrator open the staff page', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/staff')
        ->assertOk();
});

it('creates a technician with a home company and synced skills', function () {
    $this->actingAs(User::factory()->admin()->create());
    $company = Company::factory()->create();

    Volt::test('staff.index')
        ->call('startCreate')
        ->set('fName', 'Nina Technician')
        ->set('fEmail', 'nina@example.test')
        ->set('fPassword', 'secret123')
        ->set('fCompanyId', (string) $company->id)
        ->set('fSkills', [TicketCategory::Network->value, TicketCategory::Printer->value])
        ->call('save')
        ->assertHasNoErrors();

    $tech = User::where('email', 'nina@example.test')->first();

    expect($tech)->not->toBeNull()
        ->and($tech->role)->toBe(UserRole::Technician)
        ->and($tech->company_id)->toBe($company->id)
        ->and($tech->skills()->pluck('category_key')->sort()->values()->all())
        ->toBe([TicketCategory::Network->value, TicketCategory::Printer->value]);
});

it('rejects a create without a password or company', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('staff.index')
        ->call('startCreate')
        ->set('fName', 'Ghost')
        ->set('fEmail', 'ghost@example.test')
        ->call('save')
        ->assertHasErrors(['fPassword', 'fCompanyId']);

    expect(User::where('email', 'ghost@example.test')->count())->toBe(0);
});

it('replaces the skill set on edit so removals stick', function () {
    $this->actingAs(User::factory()->admin()->create());
    $company = Company::factory()->create();
    $tech = User::factory()->technician()->create(['company_id' => $company->id]);
    $tech->skills()->create(['category_key' => TicketCategory::Hardware->value]);
    $tech->skills()->create(['category_key' => TicketCategory::Software->value]);

    Volt::test('staff.index')
        ->call('startEdit', $tech->id)
        ->set('fSkills', [TicketCategory::Software->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($tech->fresh()->skills()->pluck('category_key')->all())
        ->toBe([TicketCategory::Software->value]);
});

it('toggles on-call status from the list', function () {
    $this->actingAs(User::factory()->admin()->create());
    $tech = User::factory()->technician()->create(['is_on_call' => false]);

    Volt::test('staff.index')
        ->call('toggleOnCall', $tech->id);

    expect($tech->fresh()->is_on_call)->toBeTrue();
});
