<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Volt\Volt;

it('lets an administrator open the manual submission page', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('tickets.create'))
        ->assertOk()
        ->assertSee('Submit a Ticket');
});

it('forbids non-administrators from opening the submission page (TicketPolicy::create)', function () {
    $this->actingAs(User::factory()->technician()->create());

    $this->get(route('tickets.create'))->assertForbidden();
});

it('creates a New ticket on behalf of an employee when no technician is chosen', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    $this->actingAs($admin);

    Volt::test('tickets.create')
        ->set('employeeId', (string) $employee->id)
        ->set('subject', 'Printer keeps jamming')
        ->set('description', 'Tray 2 jams on every job.')
        ->set('priority', TicketPriority::High->value)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $ticket = Ticket::sole();
    expect($ticket->status)->toBe(TicketStatus::New)
        ->and($ticket->priority)->toBe(TicketPriority::High)
        ->and($ticket->employee_id)->toBe($employee->id)
        ->and($ticket->assigned_to)->toBeNull()
        ->and($ticket->ticket_number)->toStartWith('IT-');
});

it('creates an Assigned ticket and links the technician when one is chosen', function () {
    $admin = User::factory()->admin()->create();
    $tech = User::factory()->technician()->create();
    $employee = Employee::factory()->create();
    $this->actingAs($admin);

    Volt::test('tickets.create')
        ->set('employeeId', (string) $employee->id)
        ->set('subject', 'VPN access request')
        ->set('description', 'Needs remote access for the new hire.')
        ->set('technicianId', (string) $tech->id)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect();

    $ticket = Ticket::sole();
    expect($ticket->status)->toBe(TicketStatus::Assigned)
        ->and($ticket->assigned_to)->toBe($tech->id);
});

it('rejects an empty submission with validation errors', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('tickets.create')
        ->call('submit')
        ->assertHasErrors(['employeeId', 'subject', 'description']);

    expect(Ticket::count())->toBe(0);
});
