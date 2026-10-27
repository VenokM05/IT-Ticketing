<?php

use App\Enums\NotificationType;
use App\Enums\TicketStatus;
use App\Mail\IncidentBroadcastMail;
use App\Models\DashboardNotification;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

// Phase 7: administrators broadcast an incident message to every requester with
// a live (non-closed) ticket over email and/or WhatsApp, honouring per-channel
// opt-outs, while an audit dashboard row is written for each active admin.

beforeEach(function () {
    config(['services.whatsapp.enabled' => false]);
});

it('forbids non-administrators from the broadcast page', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get(route('broadcast'))
        ->assertForbidden();
});

it('loads the broadcast page for an administrator', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('broadcast'))
        ->assertOk()
        ->assertSee('Incident Broadcast');
});

it('requires a subject and message before sending', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('broadcast.index')
        ->call('send')
        ->assertHasErrors(['subject', 'message']);
});

it('emails every requester with a live ticket and skips closed-ticket requesters', function () {
    Mail::fake();

    $live1 = Employee::factory()->create();
    $live2 = Employee::factory()->create();
    $closedOnly = Employee::factory()->create();

    Ticket::factory()->create(['employee_id' => $live1->id, 'status' => TicketStatus::InProgress]);
    Ticket::factory()->create(['employee_id' => $live2->id, 'status' => TicketStatus::New]);
    Ticket::factory()->create(['employee_id' => $closedOnly->id, 'status' => TicketStatus::Closed]);

    $this->actingAs(User::factory()->admin()->create());

    Volt::test('broadcast.index')
        ->set('subject', 'Planned maintenance')
        ->set('message', 'Systems offline Saturday 8pm.')
        ->set('sendWhatsApp', false)
        ->call('send')
        ->assertHasNoErrors();

    Mail::assertSent(IncidentBroadcastMail::class, 2);
});

it('honours a per-user email opt-out during a broadcast', function () {
    Mail::fake();

    $emp = Employee::factory()->create(['email' => 'optout@example.com']);
    Ticket::factory()->create(['employee_id' => $emp->id, 'status' => TicketStatus::New]);
    User::factory()->create(['email' => 'optout@example.com', 'notify_email' => false]);

    $this->actingAs(User::factory()->admin()->create());

    Volt::test('broadcast.index')
        ->set('subject', 'Outage')
        ->set('message', 'Investigating.')
        ->set('sendWhatsApp', false)
        ->call('send');

    Mail::assertNothingSent();
});

it('sends WhatsApp to an enrolled requester when the channel is enabled', function () {
    Mail::fake();
    config(['services.whatsapp.enabled' => true]);
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.BC']]], 200)]);

    $emp = Employee::factory()->create(['whatsapp_number' => '639171234567']);
    Ticket::factory()->create(['employee_id' => $emp->id, 'status' => TicketStatus::New]);

    $this->actingAs(User::factory()->admin()->create());

    Volt::test('broadcast.index')
        ->set('subject', 'Outage')
        ->set('message', 'Network down.')
        ->set('sendEmail', false)
        ->set('sendWhatsApp', true)
        ->call('send');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages'));
});

it('writes an audit dashboard row for every active admin', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create(['notify_email' => false]);
    $emp = Employee::factory()->create();
    Ticket::factory()->create(['employee_id' => $emp->id, 'status' => TicketStatus::New]);

    $this->actingAs($admin);

    Volt::test('broadcast.index')
        ->set('subject', 'Outage')
        ->set('message', 'Investigating.')
        ->set('sendWhatsApp', false)
        ->call('send');

    expect(DashboardNotification::query()
        ->where('user_id', $admin->id)
        ->where('type', NotificationType::IncidentBroadcast->value)
        ->count())->toBe(1);
});
