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
*/

return [
    'auto_assign' => env('TICKETS_AUTO_ASSIGN', true),
];
