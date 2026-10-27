<?php

use App\Models\CannedResponse;
use App\Models\Ticket;
use App\Models\User;
use Livewire\Volt\Volt;

// Canned responses are admin-managed macros that technicians drop into progress
// notes and replies, with ticket placeholders substituted on insert.

it('forbids non-admins from the canned responses page', function () {
    $this->actingAs(User::factory()->technician()->create())
        ->get('/settings/canned-responses')
        ->assertForbidden();
});

it('creates a canned response with author recorded', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Volt::test('settings.canned-responses')
        ->call('startCreate')
        ->set('fTitle', 'Password reset')
        ->set('fBody', 'Reset completed for {{requester_name}} on {{ticket_number}}.')
        ->call('save')
        ->assertHasNoErrors();

    $macro = CannedResponse::firstWhere('title', 'Password reset');

    expect($macro)->not->toBeNull()
        ->and($macro->created_by)->toBe($admin->id)
        ->and($macro->body)->toBe('Reset completed for {{requester_name}} on {{ticket_number}}.');
});

it('requires a title and body', function () {
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('settings.canned-responses')
        ->call('startCreate')
        ->call('save')
        ->assertHasErrors(['fTitle', 'fBody']);

    expect(CannedResponse::count())->toBe(0);
});

it('deletes a canned response', function () {
    $this->actingAs(User::factory()->admin()->create());
    $macro = CannedResponse::create(['title' => 'Bye', 'body' => 'Closing.']);

    Volt::test('settings.canned-responses')
        ->call('delete', $macro->id);

    expect(CannedResponse::find($macro->id))->toBeNull();
});

it('renders ticket placeholders against the request', function () {
    $ticket = Ticket::factory()->create([
        'employee_id' => null,
        'requester_name' => 'Juan Dela Cruz',
        'subject' => 'VPN access',
    ]);

    $macro = CannedResponse::create([
        'title' => 'Ack',
        'body' => 'Re: {{subject}} ({{ticket_number}}) — thanks {{requester_name}}.',
    ]);

    expect($macro->render($ticket))
        ->toBe('Re: VPN access ('.$ticket->ticket_number.') — thanks Juan Dela Cruz.');
});

it('inserts a macro into the note composer with placeholders filled', function () {
    $this->actingAs(User::factory()->admin()->create());

    $ticket = Ticket::factory()->create([
        'employee_id' => null,
        'requester_name' => 'Maria',
        'subject' => 'Printer jam',
    ]);

    $macro = CannedResponse::create([
        'title' => 'Greeting',
        'body' => 'Hi {{requester_name}}, working on {{ticket_number}}.',
    ]);

    Volt::test('tickets.show', ['ticket' => $ticket])
        ->set('noteMacroId', (string) $macro->id)
        ->call('insertNoteMacro')
        ->assertSet('noteBody', 'Hi Maria, working on '.$ticket->ticket_number.'.');
});
