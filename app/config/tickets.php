<?php

/*
|--------------------------------------------------------------------------
| Ticket intake behaviour
|--------------------------------------------------------------------------
| "Auto assign" routes a freshly created ticket (web form, WhatsApp, admin
| entry) to an IT technician instead of leaving it in the unassigned New
| queue. The technician is chosen from the ticket's company, preferring the
| one with the fewest open tickets so work spreads across the team. Disable
| with TICKETS_AUTO_ASSIGN=false to send everything back to manual triage.
|
| "SLA" targets are calendar-hour budgets per priority: how long until a first
| response is due and until resolution is due, counted from ticket creation.
| Each is overridable per environment (see the env() defaults below).
*/

return [
    'auto_assign' => env('TICKETS_AUTO_ASSIGN', true),

    // [first-response hours, resolution hours] counted in plain calendar time.
    'sla' => [
        'critical' => [
            'response' => (int) env('SLA_CRITICAL_RESPONSE_HOURS', 1),
            'resolve' => (int) env('SLA_CRITICAL_RESOLVE_HOURS', 4),
        ],
        'high' => [
            'response' => (int) env('SLA_HIGH_RESPONSE_HOURS', 4),
            'resolve' => (int) env('SLA_HIGH_RESOLVE_HOURS', 8),
        ],
        'medium' => [
            'response' => (int) env('SLA_MEDIUM_RESPONSE_HOURS', 8),
            'resolve' => (int) env('SLA_MEDIUM_RESOLVE_HOURS', 24),
        ],
        'low' => [
            'response' => (int) env('SLA_LOW_RESPONSE_HOURS', 24),
            'resolve' => (int) env('SLA_LOW_RESOLVE_HOURS', 72),
        ],
    ],

    // Fraction of an unmet SLA window that, once consumed, flags it "at risk".
    'sla_at_risk_ratio' => (float) env('SLA_AT_RISK_RATIO', 0.75),

    // Attachment hardening (PRD Phase 8, Rec #9): only these MIME types are
    // accepted when ingesting ticket media; anything else (executables, scripts,
    // archives) is rejected. Stored on the private disk, never the webroot. Sizes
    // are capped in kilobytes. Overridable per environment.
    'attachments' => [
        'max_size_kb' => (int) env('ATTACHMENT_MAX_SIZE_KB', 20_480),
        'allowed_mime_types' => [
            // images
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
            // pdf
            'application/pdf',
            // office documents
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            // plain text notes
            'text/plain',
        ],
    ],
];
