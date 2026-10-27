<?php

use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

/*
 * Public, no-login ticket submission (process.md item 2). The requester is not an
 * enrolled employee, so the ticket carries a self-declared snapshot; the typed company
 * is stamped only when it matches an active record, otherwise it falls to "Unassigned".
 */

beforeEach(function () {
    // The per-IP throttle is cache-backed. Each submit increments it even when
    // validation later fails, so clear it (both the loopback and empty-IP keys)
    // to keep the tests from leaking counts into one another.
    RateLimiter::clear('public-ticket:127.0.0.1');
    RateLimiter::clear('public-ticket:');
});

it('lets a visitor load the public form without logging in', function () {
    $this->get('/submit-ticket')
        ->assertOk()
        ->assertSee('Submit an IT request');
});

it('creates a company-less ticket from a public submission', function () {
    Volt::test('public.submit-ticket')
        ->set('name', 'Juan')
        ->set('company', 'Initech')            // unknown company
        ->set('contact', 'juan@initech.test')
        ->set('subject', 'Printer jam')
        ->set('description', 'Floor 2 printer keeps jamming.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('ticketNumber', fn ($v) => filled($v));

    $ticket = Ticket::sole();

    expect($ticket->employee_id)->toBeNull()
        ->and($ticket->company_id)->toBeNull()
        ->and($ticket->requester_name)->toBe('Juan')
        ->and($ticket->requester_contact)->toBe('juan@initech.test')
        ->and($ticket->requester_company)->toBe('Initech')
        // Detail view preserves the claimed company; reports still bucket it as Unassigned.
        ->and($ticket->companyLabel())->toBe('Initech');
});

it('stamps the company when the typed name matches an active one (case-insensitive)', function () {
    $acme = Company::factory()->create(['name' => 'Acme Manufacturing']);

    Volt::test('public.submit-ticket')
        ->set('name', 'Rosa')
        ->set('company', 'acme manufacturing')
        ->set('contact', 'rosa@acme.test')
        ->set('subject', 'VPN unreachable')
        ->set('description', 'Cannot reach the VPN from home.')
        ->call('submit')
        ->assertHasNoErrors();

    $ticket = Ticket::sole();

    expect($ticket->company_id)->toBe($acme->id)
        ->and($ticket->requester_company)->toBeNull();
});

it('requires the core requester fields', function () {
    Volt::test('public.submit-ticket')
        ->call('submit')
        ->assertHasErrors(['name', 'company', 'contact', 'subject', 'description']);

    expect(Ticket::count())->toBe(0);
});

it('stores the chosen problem category and the optional AnyDesk ID', function () {
    Volt::test('public.submit-ticket')
        ->set('name', 'Ana')
        ->set('company', 'Initech')
        ->set('contact', 'ana@initech.test')
        ->set('subject', 'Cannot print')
        ->set('description', 'Printer offline since this morning.')
        ->set('category', 'printer')
        ->set('anydeskId', ' 123 456 789 ')
        ->call('submit')
        ->assertHasNoErrors();

    $ticket = Ticket::sole();

    expect($ticket->category->key)->toBe('printer')
        // Trimmed on the way in, never blank-padded.
        ->and($ticket->anydesk_id)->toBe('123 456 789');
});

it('treats the AnyDesk ID as optional and rejects unknown categories', function () {
    Volt::test('public.submit-ticket')
        ->set('name', 'Ben')
        ->set('company', 'Initech')
        ->set('contact', 'ben@initech.test')
        ->set('subject', 'Outlook password')
        ->set('description', 'Locked out of my mailbox.')
        ->set('category', 'vpn-tunnel')      // not a TicketCategory value
        ->call('submit')
        ->assertHasErrors(['category']);

    expect(Ticket::count())->toBe(0);

    Volt::test('public.submit-ticket')
        ->set('name', 'Ben')
        ->set('company', 'Initech')
        ->set('contact', 'ben@initech.test')
        ->set('subject', 'Outlook password')
        ->set('description', 'Locked out of my mailbox.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Ticket::sole()->anydesk_id)->toBeNull();
});

it('rate limits public submissions per network', function () {
    for ($i = 0; $i < 8; $i++) {
        Volt::test('public.submit-ticket')
            ->set('name', "Visitor $i")
            ->set('company', 'Unknown Co')
            ->set('contact', "v$i@example.test")
            ->set('subject', "Issue $i")
            ->set('description', 'Some detail.')
            ->call('submit');
    }

    // First five pass the throttle; the rest are rejected without creating tickets.
    expect(Ticket::count())->toBe(5);
});

it('silently drops a honeypot submission', function () {
    Volt::test('public.submit-ticket')
        ->set('name', 'Bot')
        ->set('company', 'Acme')
        ->set('contact', 'bot@spam.test')
        ->set('subject', 'Buy now')
        ->set('description', 'spam spam')
        ->set('website', 'http://spam.example')   // only bots fill this
        ->call('submit');

    expect(Ticket::count())->toBe(0);
});

it('surfaces the public requester in the admin list and detail', function () {
    $admin = User::factory()->admin()->create();

    Volt::test('public.submit-ticket')
        ->set('name', 'Mira')
        ->set('company', 'Initech')
        ->set('contact', 'mira@x.test')
        ->set('subject', 'Laptop will not power on')
        ->set('description', 'No lights, no fan.')
        ->call('submit');

    $ticket = Ticket::sole();

    $this->actingAs($admin)->get(route('tickets'))->assertOk()->assertSee('Mira');
    $this->actingAs($admin)->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Mira')
        ->assertSee('Web request');
});
