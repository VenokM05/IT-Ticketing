<?php

use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ReportService;

/**
 * One fixed data set covering the reporting maths:
 *  A: resolved, tech1, created 01-05, +30 first response, +90 resolution
 *  B: closed,   tech1, created 01-05, +60 first response, +180 resolution, +200 closure
 *  C: in progress (open), tech2, created 02-10, +15 first response
 *  D: new (open), unassigned, created 02-10
 */
function reportFixtures(): void
{
    $ops = Department::create(['name' => 'Operations']);
    $it = Department::create(['name' => 'IT']);
    $tech1 = User::factory()->technician()->create(['name' => 'Tech One']);
    $tech2 = User::factory()->technician()->create(['name' => 'Tech Two']);
    $empIt = Employee::factory()->create(['department_id' => $it->id, 'name' => 'It Person']);
    $empOps = Employee::factory()->create(['department_id' => $ops->id, 'name' => 'Ops Person']);

    $jan = '2026-01-05 08:00:00';
    $feb = '2026-02-10 08:00:00';

    Ticket::factory()->create(['ticket_number' => 'IT-2026-000001', 'employee_id' => $empIt->id, 'assigned_to' => $tech1->id, 'status' => TicketStatus::Resolved, 'created_at' => $jan, 'first_response_at' => '2026-01-05 08:30:00', 'resolved_at' => '2026-01-05 09:30:00']);
    Ticket::factory()->create(['ticket_number' => 'IT-2026-000002', 'employee_id' => $empOps->id, 'assigned_to' => $tech1->id, 'status' => TicketStatus::Closed, 'created_at' => $jan, 'first_response_at' => '2026-01-05 09:00:00', 'resolved_at' => '2026-01-05 11:00:00', 'closed_at' => '2026-01-05 11:20:00']);
    Ticket::factory()->create(['ticket_number' => 'IT-2026-000003', 'employee_id' => $empIt->id, 'assigned_to' => $tech2->id, 'status' => TicketStatus::InProgress, 'created_at' => $feb, 'first_response_at' => '2026-02-10 08:15:00']);
    Ticket::factory()->create(['ticket_number' => 'IT-2026-000004', 'employee_id' => $empOps->id, 'status' => TicketStatus::New, 'created_at' => $feb]);
}

it('computes headline totals from the filtered set (Phase 6)', function () {
    reportFixtures();
    $report = app(ReportService::class)->report([]);

    expect($report['totals'])->toBe([
        'created' => 4,
        'open' => 3,
        'resolved' => 1,
        'closed' => 1,
        'unassigned' => 1,
    ]);
});

it('computes response and resolution metrics over the tickets that have each milestone', function () {
    reportFixtures();
    $m = app(ReportService::class)->report([])['metrics'];

    // first response: 30, 60, 15 -> avg 35.0 over 3 measured
    expect($m['first_response_avg_minutes'])->toBe(35.0)->and($m['first_response_measured'])->toBe(3)
        // resolution: 90, 180 -> avg 135.0, slowest 180
        ->and($m['resolution_avg_minutes'])->toBe(135.0)->and($m['resolution_measured'])->toBe(2)->and($m['resolution_max_minutes'])->toBe(180)
        // closure: only B -> 200
        ->and($m['closure_avg_minutes'])->toBe(200.0)->and($m['closure_measured'])->toBe(1)
        // 2 of 4 resolved-or-closed
        ->and($m['resolution_rate'])->toBe(50.0);
});

it('breaks down counts by status and priority with zero-filled statuses', function () {
    reportFixtures();
    $report = app(ReportService::class)->report([]);

    $statusCounts = collect($report['by_status'])->mapWithKeys(fn ($r) => [$r['status']->value => $r['count']]);
    expect($statusCounts->all())->toBe(['new' => 1, 'assigned' => 0, 'in_progress' => 1, 'pending' => 0, 'resolved' => 1, 'closed' => 1]);
});

it('summarises per-technician workload including an unassigned bucket', function () {
    reportFixtures();
    $workload = collect(app(ReportService::class)->report([])['workload'])->keyBy('technician');

    expect($workload['Tech One'])->toMatchArray(['assigned' => 2, 'open' => 1, 'resolved' => 2, 'avg_resolution_minutes' => 135.0])
        ->and($workload['Tech Two'])->toMatchArray(['assigned' => 1, 'open' => 1, 'resolved' => 0])
        ->and($workload['Unassigned'])->toMatchArray(['assigned' => 1, 'open' => 1, 'resolved' => 0]);
});

it('builds daily and monthly created/resolved/closed trends', function () {
    reportFixtures();
    $report = app(ReportService::class)->report([]);

    $daily = collect($report['daily'])->keyBy('period');
    expect($daily['2026-01-05'])->toBe(['period' => '2026-01-05', 'created' => 2, 'resolved' => 2, 'closed' => 1])
        ->and($daily['2026-02-10'])->toBe(['period' => '2026-02-10', 'created' => 2, 'resolved' => 0, 'closed' => 0]);

    $monthly = collect($report['monthly'])->keyBy('period');
    expect($monthly->keys()->all())->toBe(['2026-01', '2026-02'])
        ->and($monthly['2026-01']['created'])->toBe(2)
        ->and($monthly['2026-02']['created'])->toBe(2);
});

it('scopes the report to a date range', function () {
    reportFixtures();
    $february = app(ReportService::class)->report(['from' => '2026-02-01', 'to' => '2026-02-28']);

    expect($february['totals']['created'])->toBe(2)
        ->and($february['totals']['open'])->toBe(2)
        ->and($february['metrics']['resolution_measured'])->toBe(0);
});

it('restricts the report to a single technician', function () {
    reportFixtures();
    $tech1 = User::query()->where('name', 'Tech One')->value('id');
    $report = app(ReportService::class)->report(['technician' => (string) $tech1]);

    expect($report['totals']['created'])->toBe(2)
        ->and(collect($report['by_department'])->keyBy('department')['IT']['count'])->toBe(1)
        ->and(collect($report['by_department'])->keyBy('department')['Operations']['count'])->toBe(1);
});

it('renders the reports page for an administrator', function () {
    reportFixtures();
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('reports'))
        ->assertOk()
        ->assertSee('Technician workload')
        ->assertSee('Tech One');
});

it('forbids non-administrators from the reports page (Phase 6 permission boundary)', function () {
    $this->actingAs(User::factory()->technician()->create());
    $this->get(route('reports'))->assertForbidden();
});

it('streams a CSV export of the filtered tickets for an administrator', function () {
    reportFixtures();
    $this->actingAs(User::factory()->admin()->create());

    $response = $this->get(route('reports.export'));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    expect($csv)->toContain('Ticket,Subject,Employee')
        ->and($csv)->toContain('IT-2026-000001')
        ->and($csv)->toContain('IT-2026-000004');
});

it('forbids non-administrators from the CSV export', function () {
    $this->actingAs(User::factory()->technician()->create());
    $this->get(route('reports.export'))->assertForbidden();
});
