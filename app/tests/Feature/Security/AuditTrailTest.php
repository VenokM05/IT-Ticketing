<?php

use App\Enums\TicketStatus;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Company;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Livewire\Volt\Volt;

// Phase 8 (Rec #10): configuration and classification edits are recorded in an
// append-only audit trail, and secrets are never captured in the payload.

it('audits a manual classification change with before/after and the actor', function () {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create([
        'priority_id' => Priority::resolve('low')->id,
        'category_id' => Category::resolve('general')->id,
    ]);

    app(TicketService::class)->updateClassification($ticket, Priority::resolve('high'), Category::resolve('general'), $admin);

    $log = ActivityLog::query()->where('event', 'ticket.classification_updated')->sole();
    expect($log->actor_id)->toBe($admin->id)
        ->and($log->subject_type)->toBe(Ticket::class)
        ->and($log->subject_id)->toBe($ticket->id)
        ->and($log->properties['before']['priority'])->toBe('low')
        ->and($log->properties['after']['priority'])->toBe('high');
});

it('audits a manual status transition', function () {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::New]);

    app(TicketService::class)->changeStatus($ticket, TicketStatus::Assigned, $admin, 'triage');

    $log = ActivityLog::query()->where('event', 'ticket.status_changed')->sole();
    expect($log->actor_id)->toBe($admin->id)
        ->and($log->properties['from'])->toBe('new')
        ->and($log->properties['to'])->toBe('assigned');
});

it('records a company creation on the admin page', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('companies.index')
        ->set('fName', 'Globex Corporation')
        ->set('fCode', 'glx')
        ->call('save')
        ->assertHasNoErrors();

    $log = ActivityLog::query()->where('event', 'company.created')->where('subject_type', Company::class)->sole();
    expect($log->properties['name'])->toBe('Globex Corporation')
        ->and($log->properties['code'])->toBe('GLX');
});

it('records a WhatsApp key change with only the masked secret', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Volt::test('settings.whatsapp')
        ->set('apiKey', 'EAAverysecrettoken1234')
        ->call('save')
        ->assertHasNoErrors();

    $log = ActivityLog::query()->where('event', 'whatsapp.token_updated')->sole();
    expect($log->actor_id)->toBe($admin->id)
        ->and($log->properties['masked'])->toBe('****1234')
        ->and(json_encode($log->properties))->not->toContain('EAAverysecrettoken');

    Volt::test('settings.whatsapp')->call('remove');
    expect(ActivityLog::query()->where('event', 'whatsapp.token_cleared')->count())->toBe(1);
});

it('exposes the audit trail page to administrators only', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('audit'))
        ->assertOk()
        ->assertSee('Audit Trail');

    $this->actingAs(User::factory()->technician()->create())
        ->get(route('audit'))
        ->assertForbidden();
});
