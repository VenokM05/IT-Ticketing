<?php

use App\Models\Priority;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

// Phase 9 (Rec #1): priorities moved to a DB-managed lookup that now also owns
// the per-priority SLA hour budgets (Phase 1). Editing a budget here drives the
// deadlines stamped on subsequent tickets.

afterEach(fn () => Carbon::setTestNow());

it('ships the four enum priorities with their seeded SLA budgets', function () {
    expect(Priority::query()->orderBy('sort')->pluck('key')->all())
        ->toBe(['critical', 'high', 'medium', 'low'])
        ->and(Priority::firstWhere('key', 'critical')->sla_response_hours)->toBe(1)
        ->and(Priority::firstWhere('key', 'critical')->sla_resolve_hours)->toBe(4)
        ->and(Priority::firstWhere('key', 'low')->sla_resolve_hours)->toBe(72);
});

it('exposes the priorities page to administrators only', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get(route('settings.priorities'))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('settings.priorities'))->assertOk()->assertSee('Priorities');
});

it('an admin-edited SLA budget drives the deadlines on new tickets', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('settings.priorities')
        ->call('startEdit', Priority::firstWhere('key', 'high')->id)
        ->set('fResponse', '3')
        ->set('fResolve', '12')
        ->call('save')
        ->assertHasNoErrors();

    $ticket = app(TicketService::class)->createTicket([
        'subject' => 'Office switch offline',
        'description' => 'No link on the core switch.',
        'priority' => 'high',
    ]);

    expect($ticket->response_due_at->toDateTimeString())->toBe('2026-11-01 12:00:00')
        ->and($ticket->resolve_due_at->toDateTimeString())->toBe('2026-11-01 21:00:00');
});

it('lets an admin add a new priority and rejects a duplicate key', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('settings.priorities')
        ->call('startCreate')
        ->set('fKey', 'urgent')
        ->set('fLabel', 'Urgent')
        ->set('fResponse', '2')
        ->set('fResolve', '6')
        ->set('fSort', '5')
        ->call('save')
        ->assertHasNoErrors();

    expect(Priority::firstWhere('key', 'urgent')->sla_response_hours)->toBe(2);

    Volt::test('settings.priorities')
        ->call('startCreate')
        ->set('fKey', 'urgent')
        ->set('fLabel', 'Urgent again')
        ->call('save')
        ->assertHasErrors('fKey');
});
