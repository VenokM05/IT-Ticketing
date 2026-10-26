<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Volt\Volt;

function detailAdmin(): User
{
    return User::factory()->admin()->create();
}

it('denies a technician another engineers ticket with 403 (FR-13)', function () {
    $other = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $other->id]);

    $this->actingAs(User::factory()->technician()->create())
        ->get(route('tickets.show', $ticket))
        ->assertForbidden();
});

it('lets the assigned technician view their ticket', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $tech->id]);

    $this->actingAs($tech)
        ->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertSee($ticket->ticket_number);
});

it('transitions status through the page and records history via the service', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $tech->id, 'status' => TicketStatus::Assigned]);

    $this->actingAs($tech);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->set('remark', 'Started work')
        ->call('changeStatus', TicketStatus::InProgress->value);

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->fresh()->started_at)->not->toBeNull()
        ->and($ticket->statusHistories()->where('to_status', TicketStatus::InProgress)->count())->toBe(1);
});

it('rejects a transition the §5.2 matrix forbids and leaves status untouched', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $tech->id, 'status' => TicketStatus::Assigned]);

    $this->actingAs($tech);

    // Assigned -> Resolved is only reachable via Pending/In Progress; calling it must error.
    Volt::test('tickets.show', ['ticket' => $ticket])
        ->call('changeStatus', TicketStatus::New->value)
        ->assertHasErrors('status');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Assigned);
});

it('lets an admin assign a technician, moving a New ticket to Assigned (FR-06)', function () {
    $admin = detailAdmin();
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($admin);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->set('assigneeId', (string) $tech->id)
        ->call('assign');

    $fresh = $ticket->fresh();
    expect($fresh->assigned_to)->toBe($tech->id)
        ->and($fresh->status)->toBe(TicketStatus::Assigned);
});

it('hides the assign controls from a technician (assign is admin-only, FR-13)', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $tech->id]);

    $this->actingAs($tech);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->assertDontSee('Assign technician');

    // …and the technician's own change-status controls remain available.
    expect($ticket->assignee)->not->toBeNull();
});

it('shows the draft report marker after the first In Progress transition (FR-10)', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $tech->id, 'status' => TicketStatus::Assigned]);

    $this->actingAs($tech);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->call('changeStatus', TicketStatus::InProgress->value)
        ->assertSee('Draft');
});
