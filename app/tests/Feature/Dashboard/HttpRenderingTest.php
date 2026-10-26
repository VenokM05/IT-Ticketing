<?php

use App\Models\Ticket;
use App\Models\User;

// Full HTTP stack (route + auth middleware + layouts.app + Volt view),
// complementing the component-only Volt::test specs elsewhere.

function httpAdmin(): User
{
    return User::factory()->admin()->create();
}

it('renders every dashboard page for an authenticated admin', function (string $path) {
    $this->actingAs(httpAdmin())->get($path)->assertOk();
})->with(['/dashboard', '/tickets', '/notifications']);

it('renders the ticket detail over http', function () {
    $admin = httpAdmin();
    $ticket = Ticket::factory()->create();

    $this->actingAs($admin)
        ->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertSee($ticket->ticket_number);
});

it('returns 403 over http when a technician opens an unassigned ticket', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => User::factory()->technician()->create()->id]);

    $this->actingAs($tech)->get(route('tickets.show', $ticket))->assertForbidden();
});

it('serves the technician their own detail page over http', function () {
    $tech = User::factory()->technician()->create();
    $ticket = Ticket::factory()->create(['assigned_to' => $tech->id]);

    $this->actingAs($tech)->get(route('tickets.show', $ticket))->assertOk();
});
