<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\SavedFilter;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Volt\Volt;

// Saved views + broader search (§10.2): a saved view is a per-user snapshot of the
// list filters; searching now reaches ticket descriptions and the requester snapshot.

it('searches ticket descriptions, not just number and subject', function () {
    $admin = User::factory()->admin()->create();
    Ticket::factory()->create(['subject' => 'Laptop won t charge', 'description' => 'MagSafe connector frayed badly']);
    Ticket::factory()->create(['subject' => 'Printer jam', 'description' => 'Tray 2 paper stuck']);

    $this->actingAs($admin);

    Volt::test('tickets.index')
        ->set('search', 'MagSafe')
        ->assertSee('Laptop won t charge')
        ->assertDontSee('Printer jam');
});

it('saves the current filters as a named view', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Volt::test('tickets.index')
        ->set('status', TicketStatus::InProgress->value)
        ->set('priority', TicketPriority::Critical->value)
        ->set('saveViewName', 'Hot and open')
        ->call('saveView')
        ->assertHasNoErrors();

    $view = SavedFilter::where('user_id', $admin->id)->first();

    expect($view->name)->toBe('Hot and open')
        ->and($view->payload)->toBe([
            'status' => TicketStatus::InProgress->value,
            'priority' => TicketPriority::Critical->value,
        ]);
});

it('re-saving the same name overwrites the snapshot', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Volt::test('tickets.index')
        ->set('saveViewName', 'My queue')
        ->set('status', TicketStatus::New->value)
        ->call('saveView');

    Volt::test('tickets.index')
        ->set('saveViewName', 'My queue')
        ->set('status', TicketStatus::Pending->value)
        ->call('saveView');

    expect(SavedFilter::where('user_id', $admin->id)->count())->toBe(1)
        ->and(SavedFilter::where('user_id', $admin->id)->first()->payload)
        ->toBe(['status' => TicketStatus::Pending->value]);
});

it('applies a saved view back onto the filters', function () {
    $admin = User::factory()->admin()->create();
    $view = SavedFilter::create([
        'user_id' => $admin->id,
        'name' => 'Queue',
        'payload' => ['status' => TicketStatus::Pending->value, 'priority' => TicketPriority::Low->value],
    ]);

    $this->actingAs($admin);

    Volt::test('tickets.index')
        ->call('applyView', $view->id)
        ->assertSet('status', TicketStatus::Pending->value)
        ->assertSet('priority', TicketPriority::Low->value);
});

it('keeps saved views scoped to their owner', function () {
    $mine = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $foreign = SavedFilter::create([
        'user_id' => $other->id,
        'name' => 'Theirs',
        'payload' => ['status' => TicketStatus::Closed->value],
    ]);

    $this->actingAs($mine);

    // Applying and deleting someone else's view are no-ops.
    Volt::test('tickets.index')
        ->call('applyView', $foreign->id)
        ->assertSet('status', '');

    Volt::test('tickets.index')->call('deleteView', $foreign->id);

    expect(SavedFilter::find($foreign->id))->not->toBeNull();
});
