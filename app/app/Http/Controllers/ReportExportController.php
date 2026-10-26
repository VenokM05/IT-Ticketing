<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the filtered ticket report as CSV (PRD Phase 6 "CSV/Excel exports").
 * Reuses ReportService::csvRows() so the export matches the on-screen report and
 * the same permission boundary (administrators only).
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): StreamedResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $filters = $request->only(['from', 'to', 'status', 'priority', 'category', 'technician', 'department']);
        $rows = $reports->csvRows($filters);
        $header = [
            'Ticket', 'Subject', 'Employee', 'Department', 'Category', 'Priority',
            'Status', 'Technician', 'Created', 'First Response', 'Resolved', 'Closed', 'Resolution Minutes',
        ];

        $range = trim(($filters['from'] ?? '').'_'.($filters['to'] ?? ''), '_');
        $filename = 'tickets-report'.($range ? "-{$range}" : '').'.csv';

        return response()->streamDownload(function () use ($rows, $header) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
