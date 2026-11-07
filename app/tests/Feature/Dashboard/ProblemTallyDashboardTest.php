<?php

use App\Models\Category;
use App\Models\Employee;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;

// Full HTTP stack: the tally rides the real dashboard page, and drill-down
// goes through the real tickets list, so role scoping and query hydration are
// exercised end to end.

function tallyAdmin(): User
{
    return User::factory()->admin()->create();
}

it('shows the admin problem tally with org-wide trends on the dashboard', function () {
    $admin = tallyAdmin();
    Ticket::factory()->create([
        'ticket_number' => 'IT-TALLY-100001',
        'subject' => 'Printer jam in finance',
        'category_id' => Category::resolve('printer')->id,
    ]);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Problem Tally')
        ->assertSee('Recurring issues across all companies')
        ->assertSee('Most repeated problems')
        ->assertSee('Printer')
        ->assertDontSee('My Problem Tally');
});

it('shows the technician a queue-scoped tally instead of the admin one', function () {
    $tech = User::factory()->technician()->create();

    // Empty queue -> explicit empty state, and none of the admin framing.
    $this->actingAs($tech)->get('/dashboard')
        ->assertOk()
        ->assertSee('My Problem Tally')
        ->assertSee('No tickets assigned to you')
        ->assertDontSee('Recurring issues across all companies');

    Ticket::factory()->create([
        'ticket_number' => 'IT-TALLY-100002',
        'subject' => 'Laptop wont charge',
        'category_id' => Category::resolve('hardware')->id,
        'assigned_to' => $tech->id,
    ]);

    $this->actingAs($tech)->get('/dashboard')
        ->assertOk()
        ->assertSee('Recurring issues in your queue')
        ->assertSee('Hardware')
        ->assertSee('Start here');
});

it('never lets another technician\'s queue leak into my problem tally', function () {
    $mine = User::factory()->technician()->create();
    $other = User::factory()->technician()->create();

    // Only the other technician has tickets; mine must still see the empty state.
    Ticket::factory()->create(['assigned_to' => $other->id, 'category_id' => Category::resolve('network')->id]);

    $this->actingAs($mine)->get('/dashboard')
        ->assertOk()
        ->assertSee('No tickets assigned to you');
});

it('drills a category tally click into the pre-filtered ticket list', function () {
    $printer = Ticket::factory()->create([
        'ticket_number' => 'IT-TALLY-200001',
        'subject' => 'Toner replace',
        'category_id' => Category::resolve('printer')->id,
    ]);
    $network = Ticket::factory()->create([
        'ticket_number' => 'IT-TALLY-200002',
        'subject' => 'Switch port dead',
        'category_id' => Category::resolve('network')->id,
    ]);

    $this->actingAs(tallyAdmin())
        ->get('/tickets?category=printer')
        ->assertOk()
        ->assertSee($printer->ticket_number)
        ->assertDontSee($network->ticket_number);
});

it('drills priority and repeat-subject tally clicks into filtered lists', function () {
    $high = Ticket::factory()->create([
        'ticket_number' => 'IT-TALLY-300001',
        'subject' => 'VPN keeps dropping',
        'category_id' => Category::resolve('network')->id,
        'priority_id' => Priority::resolve('high')->id,
    ]);
    $low = Ticket::factory()->create([
        'ticket_number' => 'IT-TALLY-300002',
        'subject' => 'Desk phone config',
        'category_id' => Category::resolve('network')->id,
        'priority_id' => Priority::resolve('low')->id,
    ]);

    // Category + priority chip drill-down.
    $this->actingAs(tallyAdmin())->get('/tickets?category=network&priority=high')
        ->assertOk()
        ->assertSee($high->ticket_number)
        ->assertDontSee($low->ticket_number);

    // "Most repeated problem" subject drill-down.
    $this->actingAs(tallyAdmin())->get('/tickets?search=VPN+keeps+dropping')
        ->assertOk()
        ->assertSee($high->ticket_number)
        ->assertDontSee($low->ticket_number);
});

it('gives admins the company slicer, tally CSV link and a widen-window nudge', function () {
    // Empty database: the slicer and export still render, and the empty state
    // suggests widening the window instead of shrugging.
    $this->actingAs(tallyAdmin())->get('/dashboard')
        ->assertOk()
        ->assertSee('All companies')
        ->assertSee('Widen to 26 weeks')
        ->assertSee('type=tally', false);
});

it('surfaces requester spread, incident escalation, KB nudges and fuzzy clusters in the tally', function () {
    $admin = tallyAdmin();
    $printer = Category::resolve('printer');
    $one = Employee::factory()->create();
    $two = Employee::factory()->create();

    // Confirmed pair from two people plus a differently-worded third ticket.
    Ticket::factory()->create(['ticket_number' => 'IT-TALLY-400001', 'subject' => 'Floor 3 printer offline', 'category_id' => $printer->id, 'employee_id' => $one->id]);
    Ticket::factory()->create(['ticket_number' => 'IT-TALLY-400002', 'subject' => 'Floor 3 printer offline', 'category_id' => $printer->id, 'employee_id' => $two->id]);
    Ticket::factory()->create(['ticket_number' => 'IT-TALLY-400003', 'subject' => 'Floor 3 printer is offline again', 'category_id' => $printer->id, 'employee_id' => $one->id]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('2 requesters')            // spread badge on the confirmed repeats
        ->assertSee('Escalate')                // multi-requester open problem -> incident shortcut
        ->assertSee('Draft a KB article')      // no published coverage yet
        ->assertSee('Possibly related wording'); // fuzzy tier flagged honestly
});

it('prefills the broadcast draft from a tally escalation link', function () {
    $this->actingAs(tallyAdmin())
        ->get('/broadcast?subject=Floor+3+printer+offline')
        ->assertOk()
        // The property lands in the Livewire snapshot, ready for review.
        ->assertSee('Floor 3 printer offline', false);
});
