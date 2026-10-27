<?php

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Models\EmployeeToken;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Livewire\Volt\Volt;

// Phase 10 (Rec #13): a tokenized magic link handed to a public requester lets
// them re-open their own ticket without an account — read the live status and
// post a follow-up comment. Possession of the unguessable token is the only
// credential; unknown or expired links are a 404 so nothing is disclosed.

/** @return array{0: Ticket, 1: string} */
function trackedTicket(): array
{
    $ticket = Ticket::factory()->create([
        'subject' => 'VPN keeps dropping',
        'description' => 'Frequent disconnects since Monday.',
    ]);

    return [$ticket, EmployeeToken::issue($ticket)->token];
}

it('lets a token holder view their ticket status without logging in', function () {
    [$ticket, $token] = trackedTicket();

    $this->get(route('portal.ticket', $token))
        ->assertOk()
        ->assertSee('VPN keeps dropping')
        ->assertSee($ticket->ticket_number);
});

it('rejects an unknown token', function () {
    $this->get(route('portal.ticket', 'not-a-real-token'))->assertNotFound();
});

it('rejects an expired token', function () {
    [, $token] = trackedTicket();
    EmployeeToken::firstWhere('token', $token)->forceFill(['expires_at' => now()->subDay()])->save();

    $this->get(route('portal.ticket', $token))->assertNotFound();
});

it('lets a token holder post a comment that lands as an inbound portal message', function () {
    [$ticket, $token] = trackedTicket();

    Volt::test('portal.ticket', ['token' => $token])
        ->set('comment', 'It happened again just now.')
        ->call('addComment')
        ->assertHasNoErrors();

    $message = TicketMessage::where('ticket_id', $ticket->id)->latest('id')->first();

    expect($message)->not->toBeNull()
        ->and($message->body)->toBe('It happened again just now.')
        ->and($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->channel)->toBe(MessageChannel::Portal)
        ->and($message->user_id)->toBeNull();
});

it('does not require a message body', function () {
    [, $token] = trackedTicket();

    Volt::test('portal.ticket', ['token' => $token])
        ->set('comment', '')
        ->call('addComment')
        ->assertHasErrors('comment');
});

it('issues a tracking link when a public request is filed', function () {
    Volt::test('public.submit-ticket')
        ->set('name', 'Ada Lovelace')
        ->set('company', 'Acme Manufacturing')
        ->set('contact', 'ada@example.com')
        ->set('subject', 'Laptop will not charge')
        ->set('description', 'No power light when plugged in.')
        ->set('category', 'hardware')
        ->call('submit')
        ->assertHasNoErrors();

    $ticket = Ticket::firstWhere('subject', 'Laptop will not charge');

    expect($ticket)->not->toBeNull()
        ->and(EmployeeToken::where('ticket_id', $ticket->id)->count())->toBe(1);
});
