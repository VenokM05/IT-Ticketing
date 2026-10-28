<?php

use App\Mail\TicketReceiptMail;
use App\Models\Company;
use App\Models\EmployeeToken;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

/*
 * Public intake beyond the happy path (the recommendations that grew out of the
 * submit-ticket review): the mailed receipt, the optional screenshot, impact-driven
 * priority, the bot gates, the duplicate warning, the deep-linked company and the
 * lost-link recovery. The company-picker basics and the name-matching fallback live
 * in PublicTicketSubmissionTest.
 */

beforeEach(function () {
    // Both the submit and the recovery throttle are cache-backed and count failed
    // attempts too, so clear them to keep tests from leaking into one another.
    foreach (['public-ticket', 'public-ticket-recover'] as $bucket) {
        RateLimiter::clear($bucket.':127.0.0.1');
        RateLimiter::clear($bucket.':');
    }
});

/** A real 1x1 PNG built without GD — this PHP build has no image extension. */
function intake_png_file(string $name = 'screenshot.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));
}

/**
 * Fill the form as a visitor would. Returns the tested component after a submit,
 * so each test can assert on whatever it is really about.
 */
function intake_submission(array $overrides = []): mixed
{
    $component = Volt::test('public.submit-ticket');

    foreach (array_merge([
        'name' => 'Ana',
        'company' => 'Initech',
        'contact' => '+6281111000',
        'subject' => 'No internet',
        'description' => 'The whole floor has been offline since 9am.',
    ], $overrides) as $property => $value) {
        $component->set($property, $value);
    }

    return $component->call('submit');
}

/*
|--------------------------------------------------------------------------
| #1 — the receipt is mailed, not just displayed
|--------------------------------------------------------------------------
*/

it('mails the ticket number and the tracking link when an email is given', function () {
    Mail::fake();

    intake_submission(['email' => 'ana@initech.test'])->assertHasNoErrors();

    $ticket = Ticket::sole();
    $token = EmployeeToken::sole();

    expect($ticket->requester_email)->toBe('ana@initech.test');

    Mail::assertSent(
        TicketReceiptMail::class,
        fn (TicketReceiptMail $mail) => $mail->hasTo('ana@initech.test')
            && $mail->ticket->is($ticket)
            && str_contains($mail->trackUrl, $token->token),
    );
});

it('files the request without mailing anything when no email is given', function () {
    Mail::fake();

    intake_submission()->assertHasNoErrors();

    expect(Ticket::sole()->requester_email)->toBeNull();

    Mail::assertNothingSent();
});

it('rejects an email address that is not a valid one', function () {
    Mail::fake();

    intake_submission(['email' => 'not-an-address'])->assertHasErrors(['email']);

    expect(Ticket::count())->toBe(0);

    Mail::assertNothingSent();
});

it('hands out a tracking link that actually opens the portal', function () {
    intake_submission()->assertHasNoErrors();

    $ticket = Ticket::sole();

    $this->get(route('portal.ticket', EmployeeToken::sole()->token))
        ->assertOk()
        ->assertSee($ticket->subject);
});

/*
|--------------------------------------------------------------------------
| #2 — optional screenshot, stored privately under the shared policy
|--------------------------------------------------------------------------
*/

it('stores an intake screenshot on the private disk as a ticket attachment', function () {
    Storage::fake('local');

    intake_submission(['screenshot' => intake_png_file('screen.png')])->assertHasNoErrors();

    $attachment = TicketAttachment::sole();

    expect($attachment->ticket_id)->toBe(Ticket::sole()->id)
        ->and($attachment->original_filename)->toBe('screen.png')
        ->and($attachment->mime_type)->toBe('image/png')
        ->and($attachment->uploaded_by)->toBeNull()
        ->and($attachment->storage_path)->toStartWith('attachments/')
        ->and(Storage::disk('local')->exists($attachment->storage_path))->toBeTrue();
});

it('files the request without an attachment when nothing was uploaded', function () {
    Storage::fake('local');

    intake_submission()->assertHasNoErrors();

    expect(TicketAttachment::count())->toBe(0);
});

it('rejects an upload that is not an image', function () {
    Storage::fake('local');

    intake_submission(['screenshot' => UploadedFile::fake()->createWithContent('notes.txt', 'not an image')])
        ->assertHasErrors(['screenshot']);

    expect(Ticket::count())->toBe(0)
        ->and(TicketAttachment::count())->toBe(0);
});

it('rejects an upload over the configured size ceiling', function () {
    config(['tickets.attachments.max_size_kb' => 1]);

    intake_submission(['screenshot' => UploadedFile::fake()->createWithContent('big.png', str_repeat('x', 4096))])
        ->assertHasErrors(['screenshot']);

    expect(Ticket::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| #3 — stated impact drives the priority (and its SLA clock)
|--------------------------------------------------------------------------
*/

it('files an outage as critical and stamps its SLA', function () {
    intake_submission(['impact' => 'outage'])->assertHasNoErrors();

    $ticket = Ticket::sole();

    expect($ticket->priority->key)->toBe('critical')
        ->and($ticket->response_due_at)->not->toBeNull()
        ->and($ticket->resolve_due_at)->not->toBeNull();
});

it('files a workaround-able problem as low priority', function () {
    intake_submission(['impact' => 'minor'])->assertHasNoErrors();

    expect(Ticket::sole()->priority->key)->toBe('low');
});

it('keeps the old medium default when the requester says nothing about impact', function () {
    intake_submission()->assertHasNoErrors();

    expect(Ticket::sole()->priority->key)->toBe('medium');
});

it('refuses an impact that is not one of the offered choices', function () {
    intake_submission(['impact' => 'everything'])->assertHasErrors(['impact']);

    expect(Ticket::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| #7 — time-on-page floor alongside the honeypot
|--------------------------------------------------------------------------
*/

it('silently drops a form submitted faster than a human could fill it', function () {
    config(['tickets.intake.min_fill_seconds' => 30]);

    intake_submission()->assertHasNoErrors();

    expect(Ticket::count())->toBe(0);
});

it('lets a submission through once the fill-time floor has passed', function () {
    config(['tickets.intake.min_fill_seconds' => 30]);

    Volt::test('public.submit-ticket')
        ->set('openedAt', time() - 31)
        ->set('name', 'Ana')
        ->set('company', 'Initech')
        ->set('contact', '+6281111000')
        ->set('subject', 'No internet')
        ->set('description', 'The whole floor has been offline since 9am.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Ticket::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| #8 — soft duplicate detection
|--------------------------------------------------------------------------
*/

it('warns about an open request with the same subject instead of filing a copy', function () {
    $first = intake_submission(['subject' => 'Printer jam'])->get('ticketNumber');

    expect(Ticket::count())->toBe(1);

    RateLimiter::clear('public-ticket:127.0.0.1');
    RateLimiter::clear('public-ticket:');

    intake_submission(['subject' => 'printer jam'])
        ->assertSet('duplicateOf', $first)
        ->assertSet('ticketNumber', null);

    // Same problem, second ticket not filed...
    expect(Ticket::count())->toBe(1);

    RateLimiter::clear('public-ticket:127.0.0.1');
    RateLimiter::clear('public-ticket:');

    Volt::test('public.submit-ticket')
        ->set('duplicateAck', true)
        ->set('name', 'Ana')
        ->set('company', 'Initech')
        ->set('contact', '+6281111000')
        ->set('subject', 'Printer jam')
        ->set('description', 'The whole floor has been offline since 9am.')
        ->call('submit')
        ->assertHasNoErrors();

    // ... until the requester says it is genuinely another problem.
    expect(Ticket::count())->toBe(2);
});

it('does not treat a different subject or requester as a duplicate', function () {
    intake_submission(['subject' => 'Printer jam']);

    RateLimiter::clear('public-ticket:127.0.0.1');
    RateLimiter::clear('public-ticket:');

    intake_submission(['subject' => 'Printer jam', 'contact' => '+6282222000'])->assertHasNoErrors();

    expect(Ticket::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| #9 — company deep link, and a search box only when the list needs one
|--------------------------------------------------------------------------
*/

it('preselects the company named in the link and skips the first step', function () {
    Company::factory()->create(['name' => 'Acme Manufacturing', 'code' => 'ACME']);

    $this->get('/submit-ticket?company=acme')
        ->assertOk()
        ->assertDontSee('Which company are you from?')
        ->assertSee('Acme Manufacturing');
});

it('ignores a deep link that matches no active company', function () {
    Company::factory()->create(['name' => 'Acme Manufacturing', 'code' => 'ACME']);

    $this->get('/submit-ticket?company=Globex')
        ->assertOk()
        ->assertSee('Which company are you from?');
});

it('only offers the company search box once the list is long enough to need one', function () {
    Company::factory()->count(2)->create();

    $this->get('/submit-ticket')->assertOk()->assertDontSee('Search companies');

    Company::factory()->count(6)->create();

    $this->get('/submit-ticket')->assertOk()->assertSee('Search companies');
});

/*
|--------------------------------------------------------------------------
| #4 — the forced picker is a real dialog
|--------------------------------------------------------------------------
*/

it('renders the company picker as a trapped, labelled dialog', function () {
    Volt::test('public.submit-ticket')
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false)
        ->assertSee('aria-labelledby="company-picker-heading"', false)
        ->assertSee('x-trap.noscroll', false);
});

it('drops the dialog once a company has been chosen', function () {
    $acme = Company::factory()->create();

    Volt::test('public.submit-ticket')
        ->call('chooseCompany', $acme->id)
        ->assertDontSee('role="dialog"', false);
});

/*
|--------------------------------------------------------------------------
| #10 — recovering a lost tracking link
|--------------------------------------------------------------------------
*/

it('recovers the tracking link from the ticket number and contact', function () {
    $ticket = Ticket::factory()->create([
        'employee_id' => null,
        'requester_contact' => '+6281111000',
    ]);
    $token = EmployeeToken::issue($ticket);

    // The number is matched case-insensitively, so a receipt copied in lower case works.
    Volt::test('public.submit-ticket')
        ->set('lookupNumber', strtolower($ticket->ticket_number))
        ->set('lookupContact', $ticket->requester_contact)
        ->call('findTicket')
        ->assertRedirect(route('portal.ticket', $token->token));

    // The live link is reused rather than re-minted.
    expect(EmployeeToken::where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('mints a fresh link during recovery when the original one has expired', function () {
    $ticket = Ticket::factory()->create([
        'employee_id' => null,
        'requester_contact' => 'rosa@acme.test',
    ]);
    EmployeeToken::issue($ticket)->forceFill(['expires_at' => now()->subDay()])->save();

    $component = Volt::test('public.submit-ticket')
        ->set('lookupNumber', $ticket->ticket_number)
        ->set('lookupContact', 'rosa@acme.test')
        ->call('findTicket');

    $fresh = EmployeeToken::where('ticket_id', $ticket->id)->latest('id')->first();

    expect(EmployeeToken::where('ticket_id', $ticket->id)->count())->toBe(2);

    $component->assertRedirect(route('portal.ticket', $fresh->token));
});

it('refuses a recovery when the number and contact do not both match', function () {
    $ticket = Ticket::factory()->create([
        'employee_id' => null,
        'requester_contact' => '+6281111000',
    ]);
    EmployeeToken::issue($ticket);

    Volt::test('public.submit-ticket')
        ->set('lookupNumber', $ticket->ticket_number)
        ->set('lookupContact', '+6289999999')
        ->call('findTicket')
        ->assertHasErrors(['lookupNumber']);

    // Nothing is disclosed and no second link is created.
    expect(EmployeeToken::where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('recovers a link using the email left on the request', function () {
    $ticket = Ticket::factory()->create([
        'employee_id' => null,
        'requester_contact' => '+6281111000',
        'requester_email' => 'ana@initech.test',
    ]);

    Volt::test('public.submit-ticket')
        ->set('lookupNumber', $ticket->ticket_number)
        ->set('lookupContact', 'ana@initech.test')
        ->call('findTicket')
        ->assertHasNoErrors();
});
