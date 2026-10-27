<?php

use App\Models\Category;
use App\Models\User;
use App\Services\TicketService;
use Livewire\Volt\Volt;

// Phase 9 (Rec #1): categories moved from a hard-coded enum to a DB-managed,
// admin-editable lookup. The seeded rows preserve the original enum values, and
// every place that consumed the enum now reads the table.

it('ships the original enum values as seeded categories', function () {
    expect(Category::query()->pluck('key')->sort()->values()->all())
        ->toBe(['access', 'general', 'hardware', 'network', 'printer', 'software'])
        ->and(Category::firstWhere('key', 'general')->label)->toBe('General IT Support')
        ->and(Category::firstWhere('key', 'access')->label)->toBe('Access/Permissions');
});

it('exposes the categories page to administrators only', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get(route('settings.categories'))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.categories'))->assertOk()->assertSee('Categories');
});

it('lets an admin create a category that then becomes selectable at intake', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('settings.categories')
        ->call('startCreate')
        ->set('fKey', 'telephony')
        ->set('fLabel', 'Telephony')
        ->set('fSort', '10')
        ->call('save')
        ->assertHasNoErrors();

    $category = Category::firstWhere('key', 'telephony');
    expect($category)->not->toBeNull()
        ->and($category->label)->toBe('Telephony')
        ->and($category->value)->toBe('telephony');

    // A ticket can then be filed under the new category.
    $ticket = app(TicketService::class)->createTicket([
        'subject' => 'Phone line dead',
        'description' => 'No dial tone on the desk phone.',
        'category' => 'telephony',
    ]);

    expect($ticket->category->key)->toBe('telephony');
});

it('rejects a duplicate category key', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('settings.categories')
        ->call('startCreate')
        ->set('fKey', 'printer')
        ->set('fLabel', 'Duplicate')
        ->call('save')
        ->assertHasErrors('fKey');
});

it('deactivates a category so it drops out of the intake select', function () {
    $this->actingAs(User::factory()->admin()->create());

    $printer = Category::firstWhere('key', 'printer');

    Volt::test('settings.categories')->call('toggle', $printer->id);

    expect($printer->refresh()->is_active)->toBeFalse();

    $this->get(route('tickets.create'))->assertOk()->assertDontSee('Printer');
});
