<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Volt\Volt;

// Bulk actions on the ticket list (§10.2): admin assign/close across the current
// selection, with unauthorized or non-transitionable rows skipped, not forced.

it('bulk-assigns every selected ticket to the chosen technician', function () {
    $admin = User::factory()->admin()->create();
    $tech = User::factory()->technician()->create();
    $tickets = Ticket::factory()->count(3)->create(['status' => TicketStatus::New]);

    $this->actingAs($admin);

    Volt::test('tickets.index')
        ->set('selected', $tickets->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->set('bulkAssignee', (string) $tech->id)
        ->call('bulkAssign')
        ->assertHasNoErrors();

    expect(Ticket::whereIn('id', $tickets->pluck('id'))
        ->where('assigned_to', $tech->id)
        ->where('status', TicketStatus::Assigned)
        ->count())->toBe(3);
});

it('validates that a bulk assign names a technician', function () {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::New]);

    $this->actingAs($admin);

    Volt::test('tickets.index')
        ->set('selected', [(string) $ticket->id])
        ->set('bulkAssignee', '')
        ->call('bulkAssign')
        ->assertHasErrors('bulkAssignee');

    expect($ticket->fresh()->assigned_to)->toBeNull();
});

it('refuses bulk assign for a technician (assign is admin-only)', function () {
    $tech = User::factory()->technician()->create();
    $other = User::factory()->technician()->create();
    $mine = Ticket::factory()->create(['assigned_to' => $tech->id, 'status' => TicketStatus::Assigned]);

    $this->actingAs($tech);

    // The admin-only guard aborts before any reassignment, so the ticket stays put.
    Volt::test('tickets.index')
        ->set('selected', [(string) $mine->id])
        ->set('bulkAssignee', (string) $other->id)
        ->call('bulkAssign');

    expect($mine->fresh()->assigned_to)->toBe($tech->id);
});

it('bulk-closes every selectable open ticket', function () {
    $admin = User::factory()->admin()->create();
    $tickets = Ticket::factory()->count(2)->create(['status' => TicketStatus::InProgress]);

    $this->actingAs($admin);

    Volt::test('tickets.index')
        ->set('selected', $tickets->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->call('bulkClose');

    expect(Ticket::whereIn('id', $tickets->pluck('id'))
        ->where('status', TicketStatus::Closed)
        ->count())->toBe(2);
});

it('skips already-closed tickets during a bulk close', function () {
    $admin = User::factory()->admin()->create();
    $open = Ticket::factory()->create(['status' => TicketStatus::New]);
    $closed = Ticket::factory()->create(['status' => TicketStatus::Closed]);

    $this->actingAs($admin);

    Volt::test('tickets.index')
        ->set('selected', [(string) $open->id, (string) $closed->id])
        ->call('bulkClose');

    expect($open->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($closed->fresh()->status)->toBe(TicketStatus::Closed);
});

it('a technician only closes their own tickets and cannot reach others', function () {
    $tech = User::factory()->technician()->create();
    $otherTech = User::factory()->technician()->create();
    $mine = Ticket::factory()->create(['assigned_to' => $tech->id, 'status' => TicketStatus::InProgress]);
    $foreign = Ticket::factory()->create(['assigned_to' => $otherTech->id, 'status' => TicketStatus::InProgress]);

    $this->actingAs($tech);

    // The foreign id is pruned from the selection (not on the technician's page),
    // so only the technician's own ticket is closed.
    Volt::test('tickets.index')
        ->set('selected', [(string) $mine->id, (string) $foreign->id])
        ->call('bulkClose');

    expect($mine->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($foreign->fresh()->status)->toBe(TicketStatus::InProgress);
});
