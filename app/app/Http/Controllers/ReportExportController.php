<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the filtered ticket report as CSV (PRD Phase 6 "CSV/Excel exports").
 * Reuses ReportService::csvRows() so the export matches the on-screen report and
 * the same permission boundary (administrators only). With ?type=tally it exports
 * the Problem Tally instead — the same category/priority/repeat sections the
 * admin dashboard card shows, so recurring-problem trends can go into the
 * management pack without anyone re-typing them.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): StreamedResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $filters = $request->only(['from', 'to', 'status', 'priority', 'category', 'technician', 'department', 'company']);

        return $request->query('type') === 'tally'
            ? $this->tallyCsv($request, $reports, $filters)
            : $this->ticketsCsv($request, $reports, $filters);
    }

    /**
     * Flat ticket rows: one per ticket in the filtered set.
     *
     * @param  array<string, mixed>  $filters
     */
    private function ticketsCsv(Request $request, ReportService $reports, array $filters): StreamedResponse
    {
        $rows = $reports->csvRows($filters);
        $header = [
            'Ticket', 'Subject', 'Employee', 'Company', 'Department', 'Category', 'Priority',
            'Status', 'Technician', 'Created', 'First Response', 'Resolved', 'Closed', 'Resolution Minutes', 'CSAT', 'CSAT Comment',
        ];

        $range = trim(($filters['from'] ?? '').'_'.($filters['to'] ?? ''), '_');
        $filename = 'tickets-report'.($range ? "-{$range}" : '').'.csv';

        return $this->download($filename, $header, $rows);
    }

    /**
     * Problem Tally sections as one table: category volumes, priority levels,
     * confirmed recurring problems and the fuzzy "related" clusters, each row
     * tagged with its section so Excel pivots stay sane.
     *
     * @param  array<string, mixed>  $filters
     */
    private function tallyCsv(Request $request, ReportService $reports, array $filters): StreamedResponse
    {
        $tally = $reports->problemTally($filters, (int) $request->query('weeks', 0) ?: null);

        $header = ['Section', 'Problem', 'Category', 'Priority mix', 'Tickets', 'Open', 'Share %', 'This week', 'Last week', 'Avg resolution (min)', 'Requesters', 'KB article'];

        $rows = [];
        foreach ($tally['categories'] as $row) {
            $rows[] = [
                'category', $row['label'], $row['label'],
                collect($row['by_priority'])->map(fn (array $p) => $p['label'].'='.$p['count'])->implode(' '),
                $row['count'], $row['open'], $row['share'], $row['this_week'], $row['last_week'],
                $row['avg_resolution_minutes'], '', '',
            ];
        }
        foreach ($tally['priorities'] as $row) {
            $rows[] = [
                'priority', $row['label'], $row['top_category'], $row['label'],
                $row['count'], $row['open'], '', '', '', $row['avg_resolution_minutes'], '', '',
            ];
        }
        foreach (['recurring', 'related'] as $section) {
            foreach ($tally[$section] as $row) {
                $rows[] = [
                    $section, $row['subject'], $row['category_label'], '',
                    $row['count'], $row['open'], '', '', '', '', $row['requesters'], $row['kb_title'] ?? '',
                ];
            }
        }

        $filename = "problem-tally-{$tally['start']}-to-".now()->toDateString().'.csv';

        return $this->download($filename, $header, $rows);
    }

    /**
     * @param  list<string>  $header
     * @param  list<array<int, mixed>>  $rows
     */
    private function download(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
