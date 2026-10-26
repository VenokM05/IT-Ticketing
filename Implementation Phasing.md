# Implementation Phasing Plan

## WhatsApp-Integrated IT Ticketing System

**Version:** 1.0\
**Date:** October 26, 2026\
**Source Document:** [IT Ticketing (PRD).md](./IT%20Ticketing%20(PR%20D).md) — all section references below point to the current PRD revision.\
**Status:** Planning baseline

---

## 1. How to Read This Plan

- The project runs as **7 sequential phases** with one parallelizable track (Meta platform setup).
- Every phase has explicit **entry gates**, **tasks with PRD traceability**, **deliverables**, and **exit criteria**. A phase is not done until its exit criteria pass.
- Durations are **relative estimates (weeks)**, assuming one full-stack Laravel developer. Adjust after team capacity is confirmed.
- Two items are on the **critical path because of external approval delays**: Meta business verification + template approval (starts Phase 0, gates Phase 5) and SLA/category decisions (gate Phase 6 reporting).

---

## 2. Phase Map and Dependencies

```
Phase 0 (parallel track, start immediately)
  Meta platform & approvals ──────────────┐
                                          v
Phase 1  Requirements & Environment  ──> Phase 2  Database & Ticket Core
                                              │
              ┌───────────────────────────────┤
              v                               v
        Phase 3  WhatsApp Cloud API     Phase 4  Dashboard & Notifications
              │                               │
              └───────────────┬───────────────┘
                              v
                   Phase 5  Assignment, Progress & Notifications
                              │
                              v
                   Phase 6  Reporting
                              │
                              v
                   Phase 7  Testing, Security & Deployment
```

Hard dependencies:

| Phase | Depends on | Why |
| --- | --- | --- |
| Phase 2 | Phase 1 | Categories, priorities, roles, and schema decisions must be locked before migrations |
| Phase 3 | Phase 2 + Phase 0 | Ticket core must exist to create tickets; webhook needs a public HTTPS endpoint and Meta app |
| Phase 4 | Phase 2 | Dashboard reads tickets and notifications directly; independent of WhatsApp integration |
| Phase 5 | Phase 3 + Phase 4 | Lifecycle features need live tickets and a dashboard; employee notifications need approved templates (Phase 0) |
| Phase 6 | Phase 2, 4, 5 | Reports need complete lifecycle data and the dashboard shell |
| Phase 7 | All | UAT and production release gate |

Parallelization notes:

- **Phase 4 can run fully in parallel with Phase 3** once Phase 2 exits (seeded data is enough to build the dashboard).
- Phase 0 costs no engineering time but has the longest calendar latency — start it first.

---

## 3. Phase 0 — Meta Platform and Approvals (Parallel Track)

**Owner:** Project lead / IT admin liaison\
**Duration:** 2–3 weeks calendar (mostly waiting on Meta)\
**PRD reference:** §11 (notification/template strategy), §14 Phase 3 tasks

### Entry gate

- Business legal entity details and an admin WhatsApp number available.

### Tasks

- [ ] Create Meta Business Portfolio and WhatsApp Business Account.
- [ ] Complete business verification.
- [ ] Register the business phone number and app on the WhatsApp Cloud API; note the test-number limits (100 recipients/day, real content allowed only to opted-in numbers within 24h window).
- [ ] Finalize template body text for the 8 required utility templates (§11.3) with placeholders documented per template.
- [ ] Submit all 8 templates for Meta approval (`ticket_acknowledgment`, `ticket_assigned`, `ticket_in_progress`, `ticket_pending`, `ticket_resolved`, `ticket_closed`, `ticket_status_reply`, `unregistered_guidance`).
- [ ] Record approval status per template in a tracking sheet; feed results into the `whatsapp_templates` config table (§11.3).

### Exit criteria

- Production phone number ID and access token issued (perms: `whatsapp_business_messaging`, `whatsapp_business_management`).
- Webhook app secret available for `X-Hub-Signature-256` validation.
- At minimum `ticket_acknowledgment` and `unregistered_guidance` templates approved before Phase 3 ends; all 8 approved before Phase 5 sign-off.

---

## 4. Phase 1 — Requirements and Environment Setup

**Duration:** 1 week\
**PRD reference:** §14 Phase 1

### Entry gate

- None (starts immediately after plan sign-off).

### Tasks

- [ ] Confirm ticket categories and priority levels (values + owner sign-off).
- [ ] Confirm employee registration and identification rules (§9) with the IT department.
- [ ] Draft and approve outbound notification template content (§11) — feeds Phase 0 submissions.
- [ ] Finalize status-transition rules (§5.2) and confirm the draft/finalize report flow (§2.2, FR-10).
- [ ] Define roles and permissions matrix (Admin vs Technician, §3, FR-13).
- [ ] Select supported Laravel and PHP versions together; document the choice.
- [ ] Set up source control, branching strategy, and environment configuration (`.env` handling, secrets policy per §13.1).
- [ ] Install/configure MySQL, Laravel project skeleton, authentication starter kit, and database-driven queue (§6.1).
- [ ] Define the dev/staging webhook story: a tunnel (ngrok or equivalent) from the local/dev environment to receive Meta webhooks, plus a staging environment with HTTPS.

### Deliverables

- Approved requirements addenda (categories, priorities, roles).
- Working Laravel project with auth and queue infrastructure.

### Exit criteria

- All decision logs signed off; `composer install` → migrate → serve works on a fresh machine.
- A developer can receive a test webhook payload locally through the tunnel.

---

## 5. Phase 2 — Database and Ticket Core

**Duration:** 2 weeks\
**PRD reference:** §7, §14 Phase 2

### Entry gate

- Phase 1 exit criteria met; schema-affecting decisions (categories, departments) locked.

### Tasks

- [ ] Create migrations and Eloquent models for all §7 tables: `users`, `employees`, `tickets`, `ticket_messages`, `ticket_progress_updates`, `ticket_progress_reports`, `ticket_status_histories`, `dashboard_notifications`, `whatsapp_webhook_events`, `ticket_attachments`, `departments`, `ticket_number_sequences`, `whatsapp_templates`.
- [ ] Implement the departments lookup table and ticket-number sequence mechanism (§7.11, §7.12).
- [ ] Implement employee registration records + Employee Directory CRUD and CSV import with department mapping validation (§9.1, §10.7).
- [ ] Implement ticket creation via `TicketService` (§6.3), including safe concurrent ticket-number generation.
- [ ] Implement status transitions with validation against the §5.2 matrix; record `ticket_status_histories` (FR-07, FR-08).
- [ ] Implement authorization policies (`TicketPolicy`, FR-13) — technicians scoped to assigned tickets.
- [ ] Automated tests for creation, transitions, sequence concurrency, and policies (§15.2 subset).

### Deliverables

- Database schema + model relationships.
- Ticket-management foundation usable from tinker/tests (no UI required yet).

### Exit criteria

- Concurrent ticket-creation test produces unique, correctly formatted `IT-YYYY-NNNNNN` numbers with no gaps or collisions.
- Invalid transitions rejected with recorded reason; history complete for all valid ones.
- Policy tests prove technicians cannot view unassigned tickets.

---

## 6. Phase 3 — WhatsApp Cloud API Integration

**Duration:** 2–3 weeks\
**PRD reference:** §8, §9, §14 Phase 3\
**External dependency:** Phase 0 (app, tokens, first templates)

### Entry gate

- Phase 2 exit; public HTTPS webhook endpoint reachable from Meta; Meta app configured.

### Tasks

- [ ] Webhook GET verification against `hub.verify_token` (§8.1).
- [ ] POST signature validation using raw body + app secret (§8.2).
- [ ] Persist events with composite idempotency key `{type}:{wa_message_id}` (§8.3, §7.9).
- [ ] `ProcessWhatsAppWebhook` job: sender matching, classification pipeline (§9.4), validity rules (§9.5), ticket creation, inbound message storage (§8.4).
- [ ] Unknown-sender flow with rate-limited `unregistered_guidance` reply and no ticket/data exposure (§9.2).
- [ ] Acknowledgment queued only post-commit (FR-04, §8.6 outbox rule).
- [ ] Delivery-status processing updating outbound message records (§8.5).
- [ ] Media messages: authorized download, private storage, `ticket_attachments` (§7.10).
- [ ] `WhatsAppCloudApi` service with the §11.2 outbound decision rule (window → session message; else template; else `failed_window_constraint` retry path).
- [ ] Error logging and controlled replay for failed events (§14 Phase 3 deliverables).

### Deliverables

- Working WhatsApp → ticket → acknowledgment loop on the production number (or test number with opted-in testers).
- Duplicate-message protection proven.

### Exit criteria

- Replayed webhook deliveries create zero duplicate tickets/messages (§15.1).
- Invalid signatures rejected with 4xx and logged.
- End-to-end: registered employee texts an issue → ticket appears in DB → acknowledgment received.
- Unknown number gets guidance exactly once per 24h and no ticket.

---

## 7. Phase 4 — Dashboard and Live Notifications

**Duration:** 2 weeks (parallel with Phase 3)\
**PRD reference:** §10, FR-05, §14 Phase 4

### Entry gate

- Phase 2 exit (Phase 3 NOT required — build against seeded data).

### Tasks

- [ ] Dashboard layout with Tailwind/Livewire (§6.1).
- [ ] Status counters + latest tickets list (§10.1).
- [ ] Notification bell and feed with per-user read state (§7.8, FR-05); Livewire polling 5–10s.
- [ ] Ticket list: search, filters (status/priority/category/technician/date), pagination (§10.2).
- [ ] Ticket detail page: message history, attachments, status history, progress, reports with `draft` marking, notification/delivery records (§10.3).
- [ ] Employee Directory page: CRUD, deactivate, CSV import UI, relink-approval from notification feed (§10.7, §9.3).
- [ ] Authorization enforced server-side on every page (FR-13); technician vs admin views.

### Deliverables

- Operational IT Admin dashboard against seeded or live data.

### Exit criteria

- New ticket (inserted via test or Phase 3 pipeline) appears within one poll interval without refresh (§15.3).
- Two admins see independent read state on the same notification.
- Unauthorized access attempts to Directory/tickets are denied server-side.

---

## 8. Phase 5 — Assignment, Progress and Status Notifications

**Duration:** 2 weeks\
**PRD reference:** FR-06 to FR-11, §11, §14 Phase 5\
**External dependency:** all 8 templates approved (Phase 0)

### Entry gate

- Phase 3 + Phase 4 exits; full template inventory approved in Meta.

### Tasks

- [ ] Assignment/reassignment UI with remarks, history, and assignment notification (FR-06).
- [ ] Status-transition controls with server-side matrix validation (FR-07, §10.4).
- [ ] Progress entry forms — work, findings, next steps, time spent, internal/visible flag (FR-09, §10.5).
- [ ] Draft formal report auto-created on first In Progress transition; system-known fields prefilled, technician fields empty; finalized via first progress entry (FR-10).
- [ ] Work-start WhatsApp notification withheld until report finalized (§11, FR-10).
- [ ] Status notification workflow per the FR-11 event table, routed through the §11.2 window/template rule.
- [ ] Outbound tracking, delivery reconciliation, and safe retry of `failed_window_constraint` sends (§11.4, §8.5).
- [ ] Internal notes never included in any outbound payload — hard assertion in code and tests (FR-09, §15.4).

### Deliverables

- Complete ticket lifecycle end-to-end with notifications.

### Exit criteria

- Full lifecycle walk-through passes: submit → ack → assign → In Progress (draft report) → progress entry finalizes report → employee notified → resolve → close.
- Out-of-window event correctly sends the mapped template; a failed send lands in the retry path with no blind re-send.
- Audit trail complete for creation, assignment, transitions, reports, sends (§3 history rows + FR-14).

---

## 9. Phase 6 — Reporting

**Duration:** 1.5–2 weeks\
**PRD reference:** FR-12, §12, §14 Phase 6

### Entry gate

- Phase 5 exit (lifecycle data exists); SLA rules defined: business hours, holidays, priority targets, Pending-time treatment (§12.2 dependency).

### Tasks

- [ ] Report filters UI (date, status, priority, department, category, technician) (§12.3, FR-12).
- [ ] Daily and monthly summaries; status, in-progress, workload, department-volume, category reports (FR-12 #1–7).
- [ ] Response-time and resolution-time metrics — documented definitions implemented in one shared query layer, Pending-time excluded per agreed SLA rules (FR-12 #8–9, §12.2).
- [ ] Overdue-ticket report against agreed priority targets (FR-12 #10).
- [ ] CSV/Excel export respecting filters + permissions (§12.3).
- [ ] Draft reports excluded from all exports (FR-10).
- [ ] Report queries tested against a known fixed dataset with expected-value assertions.

### Deliverables

- Operational reporting module; exportable management reports.

### Exit criteria

- §15.5 passes: counts reconcile with underlying records; exports match on-screen filters; metric calculations match hand-computed examples.

---

## 10. Phase 7 — Testing, Security and Deployment

**Duration:** 1.5–2 weeks\
**PRD reference:** §13, §15, §16, §18, §14 Phase 7

### Entry gate

- Phases 3–6 exited; staging environment with production-like configuration.

### Tasks

- [ ] Regression suite across §15 (webhook duplicates, invalid signatures, transitions, permissions, delivery failures, report accuracy).
- [ ] Failed/retried job drills; queue worker crash recovery (§8.3, §8.6).
- [ ] Security pass: HTTPS enforced, tokens never in browser code/logs, webhook signature failures tested, private attachment download authorization, output escaping (§13.1).
- [ ] Backups (DB + attachments), restore drill, failed-job monitoring, scheduler checks (§13.2, §16).
- [ ] UAT with real IT admins and technicians; fix-list triage.
- [ ] Deployment + recovery docs, environment configuration guide, user guide for admins/technicians (§19 deliverables 16–18).
- [ ] Production cutover plan incl. rollback and a monitored pilot window with a small employee group.

### Deliverables

- Tested production release + full documentation set.

### Exit criteria — **Definition of Done (§18)**

- A pilot employee sends a WhatsApp request and the validated workflow runs end-to-end: submission → auto ticket → dashboard notification → assignment → In Progress → formal report → progress history → status notifications → resolution → closure → management reporting.

---

## 11. Milestones and Timeline Summary

Assuming one developer and Phase 0 running in parallel:

| Milestone | End of | Est. week | Proves |
| --- | --- | --- | --- |
| M1 — Foundations ready | Phase 1 | W1 | Project boots, webhook reachable locally |
| M2 — Ticket core solid | Phase 2 | W3 | Concurrency-safe numbering, lifecycle rules |
| M3 — WhatsApp live | Phase 3 | W6 | Real inbound → ticket → acknowledgment |
| M4 — Dashboard operational | Phase 4 | W6 (parallel) | Admins can work tickets without WhatsApp |
| M5 — Full lifecycle | Phase 5 | W8 | End-to-end with notifications complete |
| M6 — Management reporting | Phase 6 | W10 | SLA/report module accepted |
| M7 — Production release | Phase 7 | W12 | UAT passed, DoD met |

Total estimate: **~12 weeks** (single developer, sequential Phases 1–2, 3/4 parallel, then 5–7). Two developers compress Phases 3+4 and reporting into roughly 8–9 weeks.

---

## 12. Risks That Affect Phasing

| Risk (PRD §16) | Phase impact | Planning response |
| --- | --- | --- |
| Meta template/business-verification delays | Phase 3 ack, Phase 5 exit | Start Phase 0 immediately; track approval per template; in-window free-form fallback keeps Phase 3 demoable |
| Category/priority/SLA decisions unresolved | Phase 1 exit, Phase 6 build | Decision sign-off is a Phase 1 exit criterion; SLA rules a Phase 6 entry gate |
| Webhook endpoint/HTTPS/tunnel friction in dev | Phase 1–3 | Dev tunnel + staging defined as Phase 1 task, not improvised later |
| Queue worker hosting undecided (XAMPP dev vs production host) | Phase 7 cutover | Hosting + supervisor strategy confirmed during Phase 1 environment setup |
| Ambiguous employee messages at pilot | Phase 3 classification | §9.4 pipeline + guided selection built in Phase 3; monitor pilot logs during M7 |
| Single-developer dependency on all phases | Whole timeline | Parallelize Phase 4 the moment Phase 2 exits; second developer recommended from W4 |

---

## 13. Phase Exit Checklist (Running Log)

Use this as the working status tracker; a phase closes only when every row is checked.

- [ ] **P0** Meta app live, tokens issued, 2+ templates approved
- [ ] **P0** All 8 templates approved (before P5 close)
- [ ] **P1** Categories/priorities/roles/SLA-owner decisions signed off
- [ ] **P1** Dev tunnel webhook verified locally; versions locked
- [ ] **P2** Migrations + models; sequence concurrency test green; policies green
- [ ] **P3** Idempotent inbound pipeline; ack loop live with real number; unknown-sender flow proven
- [ ] **P4** Counters, polling feed, per-admin read state, Directory CRUD/import
- [ ] **P5** Lifecycle walk-through incl. draft/finalize + withheld work-start notification
- [ ] **P6** All 10 reports + exports match known dataset
- [ ] **P7** Security pass, restore drill, UAT sign-off, production release, pilot monitored
