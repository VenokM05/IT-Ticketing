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

    /*
    |--------------------------------------------------------------------------
    | Problem tally (recurring-issue dashboards)
    |--------------------------------------------------------------------------
    |
    | "weeks" is the rolling window the tally aggregates over (admin and
    | technician dashboards both read it; the admin view can widen it).
    | "min_repeats" is how many tickets with the same normalised subject in
    | one category count as a recurring problem. "similarity_threshold" is how
    | aggressively similarly-worded subjects fold into the "possibly related"
    | tier (word-overlap 0–1). "cache_ttl" is the seconds a computed payload
    | is reused across dashboard polls; 0 disables caching (the test suite
    | does exactly that, so tallies always reflect fresh fixtures).
    */
    'tally' => [
        'weeks' => (int) env('TALLY_WEEKS', 8),
        'min_repeats' => (int) env('TALLY_MIN_REPEATS', 2),
        'similarity_threshold' => (float) env('TALLY_SIMILARITY', 0.6),
        'cache_ttl' => (int) env('TALLY_CACHE_TTL', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public intake (the no-login submit-ticket form)
    |--------------------------------------------------------------------------
    |
    | "min_fill_seconds" is the time-on-page bot defence: the form remembers when it
    | was opened and silently discards a post that arrives sooner, because no human
    | can read and fill every field that fast. Set to 0 to disable (the test suite
    | does exactly that — see Tests\TestCase).
    |
    | "duplicate_window_hours" is how far back the soft duplicate check looks for an
    | open request from the same requester with the same subject; a match asks the
    | requester to confirm instead of filing a second ticket.
    |
    */
    'intake' => [
        'min_fill_seconds' => (int) env('INTAKE_MIN_FILL_SECONDS', 5),
        'duplicate_window_hours' => (int) env('INTAKE_DUPLICATE_WINDOW_HOURS', 24),
    ],

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
