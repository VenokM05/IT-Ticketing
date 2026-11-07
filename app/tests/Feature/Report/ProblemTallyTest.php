<?php

use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Company;
use App\Models\Employee;
use App\Models\KbArticle;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Carbon;

/**
 * Problem Tally (recurring-issue dashboards): the window is Monday-aligned,
 * so fixtures anchor on this week's Monday to stay deterministic whatever
 * day the suite runs on.
 */
function tallyFixtures(): array
{
    $printer = Category::resolve('printer');
    $network = Category::resolve('network');
    $high = Priority::resolve('high');
    $medium = Priority::resolve('medium');

    $thisWeek = now()->startOfWeek(Carbon::MONDAY);
    $lastWeek = $thisWeek->copy()->subWeek();

    // Printer: two open this week (high), one closed last week (medium).
    Ticket::factory()->create(['ticket_number' => 'IT-TALLY-000001', 'subject' => 'Paper jam on floor 2', 'category_id' => $printer->id, 'priority_id' => $high->id, 'status' => TicketStatus::New, 'created_at' => $thisWeek]);
    Ticket::factory()->create(['ticket_number' => 'IT-TALLY-000002', 'subject' => 'Paper jam on floor 3', 'category_id' => $printer->id, 'priority_id' => $high->id, 'status' => TicketStatus::InProgress, 'created_at' => $thisWeek->copy()->addHours(9)]);
    Ticket::factory()->create(['ticket_number' => 'IT-TALLY-000003', 'subject' => 'Toner empty', 'category_id' => $printer->id, 'priority_id' => $medium->id, 'status' => TicketStatus::Closed, 'created_at' => $lastWeek]);
    // Network: one old open ticket.
    Ticket::factory()->create(['ticket_number' => 'IT-TALLY-000004', 'subject' => 'VPN keeps dropping', 'category_id' => $network->id, 'priority_id' => $medium->id, 'status' => TicketStatus::Pending, 'created_at' => $thisWeek->copy()->subWeeks(3)]);

    return ['printer' => $printer, 'network' => $network, 'thisWeek' => $thisWeek];
}

it('tallies problems per category with open/resolved split, share and week delta', function () {
    tallyFixtures();
    $tally = app(ReportService::class)->problemTally([], 8);

    expect($tally['totals'])->toMatchArray(['tickets' => 4, 'categories' => 2, 'recurring' => 0]);

    // Most frequent problem type leads.
    expect($tally['categories'][0]['key'])->toBe('printer');

    $printer = collect($tally['categories'])->firstWhere('key', 'printer');
    expect($printer)->toMatchArray([
        'count' => 3,
        'open' => 2,
        'resolved' => 1,
        'share' => 75.0,
        'this_week' => 2,
        'last_week' => 1,
        'delta' => 1,
    ]);
});

it('builds a Monday-aligned weekly frequency trend covering the whole window', function () {
    tallyFixtures();
    $printer = collect(app(ReportService::class)->problemTally([], 8)['categories'])->firstWhere('key', 'printer');

    expect($printer['trend'])->toHaveCount(8)
        ->and(array_sum(array_column($printer['trend'], 'count')))->toBe(3)
        ->and($printer['trend'][7])->toMatchArray(['week' => now()->startOfWeek(Carbon::MONDAY)->toDateString(), 'count' => 2])
        ->and($printer['trend'][6]['count'])->toBe(1)
        ->and($printer['trend'][0]['count'])->toBe(0);
});

it('breaks each category tally down by priority level, most common first', function () {
    tallyFixtures();
    $printer = collect(app(ReportService::class)->problemTally([], 8)['categories'])->firstWhere('key', 'printer');

    expect($printer['by_priority'])->toHaveCount(2)
        ->and($printer['by_priority'][0])->toMatchArray(['key' => 'high', 'count' => 2])
        ->and($printer['by_priority'][1])->toMatchArray(['key' => 'medium', 'count' => 1])
        ->and($printer['top_priority']['key'])->toBe('high');
});

it('tallies per priority level with the dominant category', function () {
    tallyFixtures();
    $priorities = collect(app(ReportService::class)->problemTally([], 8)['priorities'])->keyBy('key');

    expect($priorities['high'])->toMatchArray(['count' => 2, 'open' => 2, 'top_category' => 'Printer'])
        ->and($priorities['medium'])->toMatchArray(['count' => 2, 'open' => 1]);
});

it('detects recurring issues by normalised subject within a category and rates the repeats', function () {
    $network = Category::resolve('network');
    Ticket::factory()->create(['subject' => 'WiFi is down in the office', 'category_id' => $network->id, 'created_at' => now()->subDays(2)]);
    Ticket::factory()->create(['subject' => 'wifi IS down!! in the office', 'category_id' => $network->id, 'created_at' => now()->subDays(5)]);
    // Same words but a different category is a different problem, not a repeat.
    Ticket::factory()->create(['subject' => 'WiFi is down in the office', 'category_id' => Category::resolve('printer')->id, 'created_at' => now()->subDays(3)]);

    $tally = app(ReportService::class)->problemTally([], 8);

    expect($tally['totals']['recurring'])->toBe(1)
        ->and($tally['totals']['repeat_rate'])->toBe(66.7)
        ->and($tally['recurring'][0])->toMatchArray([
            'subject' => 'WiFi is down in the office',
            'category_key' => 'network',
            'count' => 2,
            'open' => 2,
        ])
        ->and($tally['recurring'][0]['ticket_ids'])->toHaveCount(2);
});

it('scopes the whole tally to one technician queue for the technician dashboard', function () {
    tallyFixtures();
    $mine = User::factory()->technician()->create();
    Ticket::factory()->create(['subject' => 'My printer jam', 'assigned_to' => $mine->id, 'category_id' => Category::resolve('printer')->id]);
    Ticket::factory()->create(['subject' => 'Another jam on my floor', 'assigned_to' => $mine->id, 'category_id' => Category::resolve('printer')->id]);

    $tally = app(ReportService::class)->problemTally(['technician' => (string) $mine->id], 8);

    expect($tally['totals']['tickets'])->toBe(2)
        ->and($tally['categories'])->toHaveCount(1)
        ->and($tally['categories'][0])->toMatchArray(['key' => 'printer', 'count' => 2]);
});

it('returns an empty-but-usable tally when the window holds no tickets', function () {
    $tally = app(ReportService::class)->problemTally([], 4);

    expect($tally['totals'])->toBe(['tickets' => 0, 'categories' => 0, 'recurring' => 0, 'repeat_rate' => 0.0])
        ->and($tally['categories'])->toBe([])
        ->and($tally['priorities'])->toBe([])
        ->and($tally['recurring'])->toBe([]);
});

it('folds similarly worded complaints into the fuzzy related tier', function () {
    $printer = Category::resolve('printer');
    Ticket::factory()->create(['subject' => 'Printer jam on 3rd floor', 'category_id' => $printer->id, 'created_at' => now()->subDays(1)]);
    Ticket::factory()->create(['subject' => 'Printer jams on 3rd floor', 'category_id' => $printer->id, 'created_at' => now()->subDays(4)]);

    $tally = app(ReportService::class)->problemTally([], 8);

    expect($tally['related'])->toHaveCount(1)
        ->and($tally['related'][0])->toMatchArray(['count' => 2, 'category_key' => 'printer', 'subject' => 'Printer jam on 3rd floor'])
        // Confidence stays honest: different wording is never a *confirmed* repeat.
        ->and($tally['recurring'])->toBe([]);
});

it('does not double-report pure exact repeats in the fuzzy tier', function () {
    $printer = Category::resolve('printer');
    Ticket::factory()->create(['subject' => 'Toner empty', 'category_id' => $printer->id]);
    Ticket::factory()->create(['subject' => 'Toner empty', 'category_id' => $printer->id]);

    $tally = app(ReportService::class)->problemTally([], 8);

    expect($tally['recurring'])->toHaveCount(1)->and($tally['related'])->toBe([]);
});

it('excludes blank and symbol-only subjects from both repeat tiers', function () {
    $general = Category::resolve('general');
    Ticket::factory()->create(['subject' => '   ', 'category_id' => $general->id]);
    Ticket::factory()->create(['subject' => '??? !!!', 'category_id' => $general->id]);

    $tally = app(ReportService::class)->problemTally([], 8);

    expect($tally['recurring'])->toBe([])->and($tally['related'])->toBe([]);
});

it('spreads each recurring problem over its distinct requesters', function () {
    $network = Category::resolve('network');
    $emp = Employee::factory()->create(['name' => 'Same Person']);
    Ticket::factory()->create(['subject' => 'VPN drops hourly', 'category_id' => $network->id, 'employee_id' => $emp->id]);
    Ticket::factory()->create(['subject' => 'VPN drops hourly', 'category_id' => $network->id, 'employee_id' => $emp->id]);
    Ticket::factory()->create(['subject' => 'VPN drops hourly', 'category_id' => $network->id]);

    $tally = app(ReportService::class)->problemTally([], 8);

    // One broken person vs an infrastructure problem is the whole story.
    expect($tally['recurring'][0])->toMatchArray(['count' => 3, 'requesters' => 2]);
});

it('hints only a published KB article as coverage for a recurring problem', function () {
    $printer = Category::resolve('printer');
    Ticket::factory()->create(['subject' => 'Printer offline from lobby', 'category_id' => $printer->id]);
    Ticket::factory()->create(['subject' => 'Printer offline from lobby', 'category_id' => $printer->id]);
    KbArticle::create(['title' => 'Printer offline from lobby', 'body' => 'Power cycle it.', 'category_key' => 'printer', 'published' => false]);

    expect(app(ReportService::class)->problemTally([], 8)['recurring'][0]['kb_title'])->toBeNull();

    KbArticle::query()->update(['published' => true]);

    expect(app(ReportService::class)->problemTally([], 8)['recurring'][0]['kb_title'])->toBe('Printer offline from lobby');
});

it('reuses the cached payload while the TTL is live and rebuilds when caching is off', function () {
    config(['tickets.tally.cache_ttl' => 90]);
    $printer = Category::resolve('printer');
    Ticket::factory()->create(['category_id' => $printer->id, 'subject' => 'One jam']);

    expect(app(ReportService::class)->problemTally([], 8)['totals']['tickets'])->toBe(1);

    Ticket::factory()->create(['category_id' => $printer->id, 'subject' => 'Two jam']);

    // Analytic data tolerates TTL staleness across poll cycles...
    expect(app(ReportService::class)->problemTally([], 8)['totals']['tickets'])->toBe(1);

    // ...and a zero TTL always rebuilds (the test-suite default).
    config(['tickets.tally.cache_ttl' => 0]);
    expect(app(ReportService::class)->problemTally([], 8)['totals']['tickets'])->toBe(2);
});

it('slices the whole tally per company for the admin company filter', function () {
    $alpha = Company::factory()->create();
    Ticket::factory()->create(['company_id' => $alpha->id, 'category_id' => Category::resolve('printer')->id]);
    Ticket::factory()->create(['company_id' => Company::factory()->create()->id, 'category_id' => Category::resolve('network')->id]);

    $tally = app(ReportService::class)->problemTally(['company' => (string) $alpha->id], 8);

    expect($tally['totals']['tickets'])->toBe(1)
        ->and($tally['categories'])->toHaveCount(1)
        ->and($tally['categories'][0]['key'])->toBe('printer');
});

it('exports the tally sections as CSV for administrators only', function () {
    tallyFixtures();

    $csv = $this->actingAs(User::factory()->admin()->create())
        ->get(route('reports.export', ['type' => 'tally', 'weeks' => 8]))
        ->assertOk()
        ->streamedContent();

    expect($csv)
        ->toContain('Section')
        ->toContain('category,Printer')
        ->toContain('priority,High')
        ->toContain('75');

    $this->actingAs(User::factory()->technician()->create())
        ->get(route('reports.export', ['type' => 'tally']))
        ->assertForbidden();
});
