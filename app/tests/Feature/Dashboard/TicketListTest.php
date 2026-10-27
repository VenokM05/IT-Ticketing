<?php

use App\Enums\TicketPriority;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Volt\Volt;

function listAdmin(): User
{
    return User::factory()->admin()->create();
}

it('shows an admin every ticket and supports filters (§10.2)', function () {
    $admin = listAdmin();
    $mine = Ticket::factory()->create(['subject' => 'Critical VPN issue', 'priority_id' => Priority::resolve('critical')->id]);
    Ticket::factory()->create(['subject' => 'Routine keyboard swap', 'priority_id' => Priority::resolve('low')->id]);

    $this->actingAs($admin);

    // Unfiltered: both visible.
    Volt::test('tickets.index')
        ->assertSee('Critical VPN issue')
        ->assertSee('Routine keyboard swap');

    // Priority filter narrows the list.
    Volt::test('tickets.index')
        ->set('priority', TicketPriority::Critical->value)
        ->assertSee('Critical VPN issue')
        ->assertDontSee('Routine keyboard swap');

    // Search by ticket number.
    Volt::test('tickets.index')
        ->set('search', $mine->ticket_number)
        ->assertSee($mine->ticket_number)
        ->assertDontSee('Routine keyboard swap');
});

it('scopes the ticket list to the technicians own queue and hides the technician filter', function () {
    $tech = User::factory()->technician()->create();
    Ticket::factory()->create(['assigned_to' => $tech->id, 'subject' => 'Assigned to me']);
    Ticket::factory()->create(['subject' => 'Assigned to nobody']);

    $this->actingAs($tech);

    Volt::test('tickets.index')
        ->assertSee('Assigned to me')
        ->assertDontSee('Assigned to nobody');
});

it('rejects guests', function () {
    $this->get('/tickets')->assertRedirect(route('login'));
});
