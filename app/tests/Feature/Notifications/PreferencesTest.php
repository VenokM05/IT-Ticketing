<?php

use App\Enums\NotificationType;
use App\Models\DashboardNotification;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEventMail;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;

// Phase 7: the email channel mirrors the in-app dashboard alert but is only
// dispatched when a recipient keeps email enabled (notify_email = true), while
// the dashboard row is always written.

it('emails the assigned technician when email is enabled', function () {
    Notification::fake();

    $tech = User::factory()->technician()->create(['notify_email' => true]);
    $ticket = Ticket::factory()->create(['assigned_to' => $tech->id]);

    app(NotificationService::class)->ticketAssigned($ticket);

    Notification::assertSentTo($tech, TicketEventMail::class);
});

it('skips the technician email when they opted out but still writes the dashboard row', function () {
    Notification::fake();

    $tech = User::factory()->technician()->create(['notify_email' => false]);
    $ticket = Ticket::factory()->create(['assigned_to' => $tech->id]);

    app(NotificationService::class)->ticketAssigned($ticket);

    Notification::assertNotSentTo($tech, TicketEventMail::class);
    expect(DashboardNotification::query()
        ->where('user_id', $tech->id)
        ->where('type', NotificationType::TicketAssigned->value)
        ->count())->toBe(1);
});

it('emails every active admin about a new ticket when email is enabled', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create(['notify_email' => true]);
    $ticket = Ticket::factory()->create();

    app(NotificationService::class)->ticketCreated($ticket);

    Notification::assertSentTo($admin, TicketEventMail::class);
});

it('does not email admins who opted out but still records the new-ticket alert', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create(['notify_email' => false]);
    $ticket = Ticket::factory()->create();

    app(NotificationService::class)->ticketCreated($ticket);

    Notification::assertNotSentTo($admin, TicketEventMail::class);
    expect(DashboardNotification::query()
        ->where('user_id', $admin->id)
        ->where('type', NotificationType::NewTicket->value)
        ->count())->toBe(1);
});

it('persists per-channel notification preferences from the profile page', function () {
    $user = User::factory()->create(['notify_email' => true, 'notify_whatsapp' => true]);
    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('notifyEmail', false)
        ->set('notifyWhatsapp', false)
        ->call('updateNotificationPreferences')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->notify_email)->toBeFalse()
        ->and($user->notify_whatsapp)->toBeFalse();
});
