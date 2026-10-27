# Ticket Process Notes

We have an Admin Dashboard (where all ticket requests display) and WhatsApp for sending ticket requests.
The system is multi-company, but companies are a **tag, not an isolation boundary**: one shared dashboard,
one shared WhatsApp number, one global ticket number sequence. Every request is attributed to the sender's
company so the IT Admin can tell which company a request came from.

## 1. WhatsApp ticket request

- A sender is identified by their **enrolled E.164 number** (`employees.whatsapp_number`), not by a structured
  message format. Name, Company, and Position are **read from the employee record**, never typed into the chat,
  so intake cannot mislabel them. Unknown numbers get registration guidance and no ticket.
- Any text from an enrolled number creates a ticket (category General, priority Medium by default; existing
  commands like `STATUS` still work). The ticket's `company_id` is stamped from the sender's enrollment inside
  `TicketService::createTicket` — the single code path shared by WhatsApp, web, and seeders.

## 2. Web ticket submission

There are two web intake paths:

- **Public, no-login form** at **/submit-ticket** (the literal "submit-ticket" page). Any visitor submits without
  an account, typing their own **Name, Company, Department, Position, contact, subject, problem category and an
  optional AnyDesk ID** (remote-assistance handle, stored as `tickets.anydesk_id`). The requester
  is not enrolled, so the ticket is stored with no `employee_id` and a self-declared snapshot. The typed company is
  **matched to an active company by name (case-insensitive) or code**; a match is stamped as the ticket's company,
  anything else is filed under the **"Unassigned"** bucket (the claimed name is still shown on the ticket). The form
  is protected by a per-IP throttle (5/min + route `throttle:20,1`) and a honeypot. Submitters get a ticket number
  to quote; they cannot open the dashboard.
- **Admin on-behalf form** at **/tickets/create** (TicketPolicy::create). The admin picks an enrolled employee; the
  form previews that person's Company / Department / Position and the ticket inherits the company exactly like
  WhatsApp intake. It also captures the optional AnyDesk ID.
- Categories are the shared `TicketCategory` enum (Hardware, Software, Network, Printer, Access/Permissions,
  General IT Support) — one list drives the public form, admin form, filters, and reports.

Both paths funnel through `TicketService::createTicket`, so company attribution stays in one place.

## 3. IT Admin dashboard & status updates

- Tickets list shows a **Company column with a company filter**; ticket detail shows requester Company + Position.
- Status is updated from the ticket page; the requester is notified on WhatsApp (existing pipeline, unchanged).

## 4. Notifications

- Dashboard notification feed covers new/updated tickets (existing) **plus** two operational alerts below
  (types `pending_tickets_alert` and `weekly_summary`), delivered to every active admin.

## 5. Weekly monitoring report (Friday)

- On-demand reports stay available; additionally every **Friday 17:00** the scheduled command
  `tickets:weekly-summary` posts the week's totals, resolution time, and per-company breakdown
  (Mon–Fri window, on the alert timezone). Reports/exports gained a **by-company** section and a company filter.

## 6. Daily 5 PM pending alert

- Every day at **17:00** the scheduled command `tickets:pending-alert` counts unresolved tickets
  ("pending" = statuses New, Assigned, In Progress, Pending — everything except Resolved/Closed; configurable
  in `config/alerts.php`), groups the count **by company**, and notifies admins. Silent when the queue is clear;
  idempotent once per day (date-keyed notification ref).

## UI / theme

- The whole app runs on a semantic design-token layer (`resources/css/app.css`): slate neutrals + indigo accent,
  `card` surface, `x-page.header` masthead, `tap` 44px targets — pages use tokens (`bg-canvas`, `text-fg-muted`,
  `border-border`, `text-accent-content`), never raw color scales.
- **Light mode is the served default**: no layout hard-codes `class="dark"`; `partials/head.blade.php` seeds
  `localStorage['flux.appearance'] = 'light'` once (guarded by a `flux.appearance.seeded` marker so an explicit
  later "System" choice survives), then `@fluxAppearance` applies it.
- Header **sun/moon toggle** (`components/theme-toggle.blade.php`) flips Light-Dark on the same Flux store the
  Settings › Appearance page binds (`$flux.appearance`), so the two controls can never drift.
- Regression guard: `tests/Feature/Theme/ThemeAndLayoutTest.php` asserts light-default HTML, seed +
  `applyAppearance` presence, toggle rendering, 200s on every primary route, and the compiled token CSS.

## Running the scheduler (Windows / XAMPP)

- Dev: `php artisan schedule:work` (runs the scheduler loop alongside the app).
- Production: create a Windows Task Scheduler task every minute running
  `C:\xampp\php\php.exe C:\xampp\htdocs\it-ticketing\app\artisan schedule:run`.
- Alert clock is `ALERT_TIMEZONE` in `.env` (default `Asia/Manila`), independent of `APP_TIMEZONE` (UTC).
- Toggle with `alerts.pending.enabled` / `alerts.weekly.enabled` (config/alerts.php);
  manual test runs: `php artisan tickets:pending-alert`, `php artisan tickets:weekly-summary [--from= --to=]`.

## Companies maintenance

- Admin-only **Companies** page (/companies): create/edit/deactivate, unique uppercased short code.
- Employee Directory and CSV import carry `company` (matched by name **or** code; legacy CSVs without the
  column still import) and `position`. Companies referenced by rows are soft-referenced: deleting a company
  nulls the tags, and reports group those tickets under "Unassigned".
