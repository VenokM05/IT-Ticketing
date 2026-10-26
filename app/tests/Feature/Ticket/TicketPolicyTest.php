<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function policyAdmin(): User
{
    return User::factory()->admin()->create();
}

function policyTech(): User
{
    return User::factory()->technician()->create();
}

it('lets an admin view any ticket', function () {
    $ticket = Ticket::factory()->create(['assigned_to' => null]);

    expect(Gate::forUser(policyAdmin())->allows('view', $ticket))->toBeTrue();
});

it('lets a technician view only tickets assigned to them', function () {
    $other = Ticket::factory()->create();
    $user = policyTech();
    $assigned = Ticket::factory()->create(['assigned_to' => $user->id]);

    expect(Gate::forUser($user)->allows('view', $assigned))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $other))->toBeFalse();
});

it('denies view to an inactive user', function () {
    $user = User::factory()->create(['is_active' => false]);
    $ticket = Ticket::factory()->create(['assigned_to' => $user->id]);

    expect(Gate::forUser($user)->allows('view', $ticket))->toBeFalse();
});

it('restricts assignment and creation to admins', function () {
    $ticket = Ticket::factory()->create();

    expect(Gate::forUser(policyAdmin())->allows('assign', $ticket))->toBeTrue()
        ->and(Gate::forUser(policyAdmin())->allows('create', Ticket::class))->toBeTrue()
        ->and(Gate::forUser(policyTech())->allows('assign', $ticket))->toBeFalse()
        ->and(Gate::forUser(policyTech())->allows('create', Ticket::class))->toBeFalse();
});

it('lets a technician update their own open ticket but not a closed one', function () {
    $user = policyTech();
    $open = Ticket::factory()->create(['assigned_to' => $user->id]);
    $closed = Ticket::factory()->create(['assigned_to' => $user->id, 'status' => TicketStatus::Closed]);

    expect(Gate::forUser($user)->allows('update', $open))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $closed))->toBeFalse();
});

it('restricts reopening to admins', function () {
    $closed = Ticket::factory()->create(['status' => TicketStatus::Closed]);

    expect(Gate::forUser(policyAdmin())->allows('reopen', $closed))->toBeTrue()
        ->and(Gate::forUser(policyTech())->allows('reopen', $closed))->toBeFalse();
});
