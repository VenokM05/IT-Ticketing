<?php

use App\Enums\NotificationType;
use App\Models\DashboardNotification;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Volt\Volt;

function readAdmin(): User
{
    return User::factory()->admin()->create();
}

function newTicketFor(User $admin): DashboardNotification
{
    $ticket = Ticket::factory()->create();

    return DashboardNotification::create([
        'user_id' => $admin->id,
        'ticket_id' => $ticket->id,
        'type' => NotificationType::NewTicket->value,
        'title' => 'New ticket '.$ticket->ticket_number,
        'message' => $ticket->subject,
    ]);
}

it('keeps read state independent per administrator for the same event (§7.8, FR-05)', function () {
    $ticket = Ticket::factory()->create();
    $a = readAdmin();
    $b = readAdmin();

    $na = DashboardNotification::create([
        'user_id' => $a->id, 'ticket_id' => $ticket->id,
        'type' => NotificationType::NewTicket->value, 'title' => 'New ticket '.$ticket->ticket_number,
    ]);
    $nb = DashboardNotification::create([
        'user_id' => $b->id, 'ticket_id' => $ticket->id,
        'type' => NotificationType::NewTicket->value, 'title' => 'New ticket '.$ticket->ticket_number,
    ]);

    $this->actingAs($a);
    Volt::test('notifications')->call('markRead', $na->id);

    expect($na->fresh()->read_at)->not->toBeNull()
        ->and($nb->fresh()->read_at)->toBeNull();
});

it('never lets a user mark another users notification read', function () {
    $a = readAdmin();
    $b = readAdmin();
    $foreign = newTicketFor($b);

    $this->actingAs($a);
    Volt::test('notifications')->call('markRead', $foreign->id);

    expect($foreign->fresh()->read_at)->toBeNull();
});

it('marks everything unread for the current user only', function () {
    $a = readAdmin();
    $b = readAdmin();
    $mine = newTicketFor($a);
    $theirs = newTicketFor($b);

    $this->actingAs($a);
    Volt::test('notifications')->call('markAllRead');

    expect($mine->fresh()->read_at)->not->toBeNull()
        ->and($theirs->fresh()->read_at)->toBeNull();
});

it('rejects guests', function () {
    $this->get('/notifications')->assertRedirect(route('login'));
});
