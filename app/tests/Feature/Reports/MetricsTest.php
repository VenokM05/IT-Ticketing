<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ReportService;

// Reporting metrics (Phase 6, Rec #8): reopen rate and CSAT alongside the existing
// response/resolution maths, plus the new CSAT columns on the CSV export.

it('computes reopen rate over ever-resolved tickets and averages CSAT', function () {
    // Resolved earlier but fell back to a live status -> counted as reopened.
    Ticket::factory()->create(['status' => TicketStatus::InProgress, 'resolved_at' => now()->subDay()]);
    // Resolved then closed -> ever-resolved but not reopened.
    Ticket::factory()->create(['status' => TicketStatus::Closed, 'resolved_at' => now()->subDay()]);

    $metrics = app(ReportService::class)->report([])['metrics'];

    expect($metrics['reopened'])->toBe(1)
        ->and($metrics['reopen_rate'])->toBe(50.0);
});

it('averages only rated tickets and ignores unrated ones', function () {
    Ticket::factory()->create(['status' => TicketStatus::Closed, 'rating' => 4]);
    Ticket::factory()->create(['status' => TicketStatus::Closed, 'rating' => 5]);
    Ticket::factory()->create(['status' => TicketStatus::Closed]); // unrated, excluded

    $metrics = app(ReportService::class)->report([])['metrics'];

    expect($metrics['csat_avg'])->toBe(4.5)
        ->and($metrics['csat_count'])->toBe(2);
});

it('reports a null CSAT average when nothing is rated', function () {
    Ticket::factory()->create(['status' => TicketStatus::Closed]);

    $metrics = app(ReportService::class)->report([])['metrics'];

    expect($metrics['csat_avg'])->toBeNull()
        ->and($metrics['csat_count'])->toBe(0);
});

it('renders the CSAT and reopen figures on the reports page', function () {
    Ticket::factory()->create(['status' => TicketStatus::Closed, 'rating' => 5]);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('reports'))
        ->assertOk()
        ->assertSee('CSAT avg')
        ->assertSee('Reopen rate');
});

it('streams the CSAT columns in the CSV export', function () {
    Ticket::factory()->create(['ticket_number' => 'IT-2026-090001', 'status' => TicketStatus::Closed, 'rating' => 4, 'rating_comment' => 'Great']);
    $this->actingAs(User::factory()->admin()->create());

    $csv = $this->get(route('reports.export'))->streamedContent();

    expect($csv)->toContain('CSAT')
        ->and($csv)->toContain('IT-2026-090001')
        ->and($csv)->toContain('4,Great');
});
