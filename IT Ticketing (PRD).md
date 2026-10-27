# Product Requirements Document (PRD)

## WhatsApp-Integrated IT Ticketing System

**Version:** 1.0\
**Date:** October 21, 2026\
**Status:** Approved for Planning\
**Project Type:** Internal IT Helpdesk and Ticket Management System

---

## 1\. Project Overview

### 1.1 Project Description

The WhatsApp-Integrated IT Ticketing System is a centralized IT helpdesk application that enables employees to submit IT issues and service requests through WhatsApp while allowing the IT Department to manage requests through a custom Laravel dashboard.

The system will use the Meta WhatsApp Business Platform Cloud API to automatically receive incoming messages, create tickets, notify IT administrators through the dashboard, track technician activities, send status notifications to employees, and generate operational and management reports.

### 1.2 Business Objective

The primary objective is to improve the current manual WhatsApp-based IT request process without requiring employees to adopt a separate ticket submission application.

The system aims to:

- Automate the creation of IT tickets from incoming WhatsApp messages.
- Centralize all IT requests in a single dashboard.
- Notify IT administrators of new requests in real time or near real time.
- Track ticket assignments, statuses, technician activities, and resolution.
- Inform employees of important ticket status changes through WhatsApp.
- Generate reports for IT operations and management.
- Improve accountability, visibility, and service performance.

### 1.3 Project Scope

The initial version will focus on internal IT support requests, including hardware, software, network, access, and general IT issues.

Employees will continue using WhatsApp as their primary submission channel. IT administrators and technicians will use the web dashboard to manage requests.

---

## 2\. Project Goals and Success Criteria

### 2.1 Goals

1. Eliminate the need for IT personnel to manually encode every WhatsApp request.
2. Provide a centralized, searchable ticket database.
3. Ensure every valid request receives a unique ticket number.
4. Provide immediate visibility of new requests to IT administrators.
5. Maintain a complete history of ticket progress and status changes.
6. Automatically notify employees of important status changes.
7. Produce accurate reports on ticket volume, workload, and resolution.

### 2.2 Success Criteria

The system will be considered ready for initial deployment when:

- Valid incoming WhatsApp requests create tickets automatically.
- Duplicate webhook deliveries do not create duplicate tickets.
- New tickets appear on the dashboard without manual page refresh.
- Administrators can assign tickets and update their statuses.
- Every status transition is recorded with its timestamp and responsible user.
- The first transition to In Progress creates an initial formal progress report draft, completed through the technician's first progress entry.
- Technicians can add timestamped progress updates.
- Important status changes trigger the appropriate employee notification workflow.
- Reports can be filtered by date, status, department, category, and technician.
- Failed messages and processing errors can be identified and retried safely.
- Unauthorized users cannot access restricted ticket information.

---

## 3\. User Roles

### 3.1 Employee

Employees use WhatsApp to:

- Submit IT issues and service requests.
- Send additional information, photos, and screenshots.
- Receive ticket acknowledgments.
- Receive notifications about assignment, work progress, pending status, resolution, and closure.
- Refer to their ticket number when following up.

Employees will not require dashboard accounts in the initial version.

### 3.2 IT Administrator

IT administrators will be able to:

- View all tickets.
- Receive dashboard notifications for new requests.
- Search and filter tickets.
- Review employee details and submitted messages.
- Assign technicians.
- Set ticket priority and category.
- Change ticket status.
- Review progress updates and formal reports.
- View ticket history.
- Generate and export reports.
- Manage ticket categories and system configuration, subject to permissions.

### 3.3 IT Technician

Technicians will be able to:

- View tickets assigned to them.
- Review ticket details and message history.
- Add work progress updates.
- Record findings, actions taken, next steps, and time spent.
- Update ticket status within permitted workflow rules.
- Record resolution details.

Technicians must not be able to access administrative functions unless explicitly authorized.

---

## 4\. Functional Requirements

### FR-01: WhatsApp Integration

The system shall integrate with the Meta WhatsApp Business Platform Cloud API.

Requirements:

- Receive incoming WhatsApp webhook events.
- Validate webhook verification requests.
- Validate incoming webhook signatures.
- Extract sender information, message content, message type, and timestamp.
- Process text messages and support media attachments.
- Send outgoing WhatsApp notifications.
- Track outgoing message IDs and delivery statuses.
- Handle API errors and retry eligible failed operations.
- Prevent duplicate processing of the same incoming message.

### FR-02: Automatic Ticket Creation

The system shall automatically create a ticket from a valid new IT request.

Each ticket shall include:

- Unique ticket number.
- Employee reference.
- Subject.
- Description.
- Category.
- Priority.
- Current status.
- Assigned technician, if applicable.
- Date and time received.
- Initial WhatsApp message reference.

Ticket number format:

`IT-YYYY-000001`

Example:

`IT-2026-000123`

Ticket numbers must be unique and generated safely under concurrent requests, using the `ticket_number_sequences` table defined in Section 7.12.

The system must support employee registration and verification. Unknown or inactive WhatsApp senders must not gain access to employee-specific ticket information. Registration, deactivation, number-relink approval, and unknown-sender handling are defined normatively in Section 9.

### FR-03: Ticket Routing and Conversation Handling

The system shall support new ticket creation and follow-up messages.

The initial implementation should provide a clear method for employees to identify whether a message is:

- A new IT request.
- Additional information for an existing ticket.
- A request for ticket status.
- A request to close or follow up on an issue.

The command grammar (`NEW TICKET`, `STATUS <ticket-number>`, `CLOSE <ticket-number>`), the message classification order, and the validity rules for new requests are defined normatively in Section 9. These rules replace optional guided-selection wording: a message is classified through the Section 9.4 order before any ticket is created or updated.

The system must not blindly attach every incoming message to the most recently created active ticket when an employee has multiple open issues. A guided selection or ticket reference workflow should be used when the intended ticket is ambiguous.

### FR-04: Ticket Acknowledgment

After a ticket is successfully created, the system shall queue an acknowledgment message containing:

- Ticket number.
- Subject or short description.
- Initial status.
- Instructions for providing additional information.

The acknowledgment shall be sent only after the ticket has been committed to the database.

### FR-05: Dashboard Notifications

The dashboard shall display a notification whenever a new ticket is successfully created.

Notification requirements:

- Live or near-real-time dashboard updates.
- Unread notification indicator.
- New ticket highlighted in the ticket queue.
- Ticket number and summary displayed.
- Notification timestamp.
- Explicit read or acknowledgment action.
- Persistent notification records.

The initial version will use dashboard notifications only for IT administrators. Separate email notifications are not required.

Recommended implementation:

- Laravel Livewire polling every 5–10 seconds for the initial version.
- Laravel Reverb and WebSockets as an optional enhancement.

### FR-06: Ticket Assignment

IT administrators shall be able to assign tickets to technicians.

The system shall record:

- Assigned technician.
- Assignment timestamp.
- Administrator responsible for the assignment.
- Relevant assignment remarks.
- Assignment changes.

Employees shall receive an assignment notification through WhatsApp when the assignment changes according to the notification rules.

### FR-07: Ticket Status Management

The system shall support the following statuses:

| Status | Description |
| --- | --- |
| New | Request received and awaiting review |
| Assigned | A technician has been assigned |
| In Progress | Investigation or repair has started |
| Pending | Work is waiting for information or a dependency |
| Resolved | The reported issue has been fixed |
| Closed | The ticket has been finalized |

The application shall validate status transitions.

A ticket moved from Pending back to In Progress shall retain its previous history.

Closed tickets shall not be modified through ordinary status updates. Reopening should require an explicitly authorized action if enabled.

### FR-08: Status History

Every status change shall create a permanent history record containing:

- Ticket ID.
- Previous status.
- New status.
- User responsible for the change, when applicable.
- Timestamp.
- Remarks.

Status history shall be available on the ticket detail page and included in relevant reports.

### FR-09: Technician Progress Tracking

Technicians shall be able to record work performed on a ticket.

Each progress entry shall include:

- Ticket number.
- Technician or author.
- Date and time.
- Work performed.
- Findings.
- Next action.
- Time spent, where applicable.
- Internal or employee-visible classification.

Internal notes shall never be automatically shared with employees through WhatsApp.

### FR-10: Formal In-Progress Reports

The first transition of a ticket to In Progress shall automatically create an initial formal progress report in `draft` status.

At creation, only fields already known by the system shall be populated:

- Ticket number.
- Subject and problem description.
- Employee and department.
- Assigned technician.
- Ticket received timestamp.
- Work-start timestamp.
- Report number and prepared-by information.

Fields that require technician input — initial action taken or work plan, initial findings, and next steps — shall remain empty in the draft. The system shall not auto-populate them with placeholders or inferred content.

The draft becomes final when the assigned technician completes the required fields and submits it, which shall be the ticket's first progress entry. Until the report is finalized:

- It is visibly marked `draft` on the ticket detail page.
- It shall not be exported, distributed, or represented as a completed report.
- The employee receives no work-start WhatsApp notification (FR-11) until the report is finalized, so notifications never accompany an empty formal report.

The initial report shall not falsely imply that a technical investigation is complete. It is a formal record that work has started.

Subsequent progress entries shall be stored separately. Additional formal reports may be generated when required, following the same draft-then-finalize rule.

Report generation must be idempotent so duplicate processing does not create duplicate initial reports. Idempotency shall be enforced by the unique constraint on `ticket_id` and `report_number` (Section 7.6), allowing one draft initial report per ticket.

### FR-11: Employee WhatsApp Notifications

The system shall notify employees of important ticket events.

| Event | Notification |
| --- | --- |
| Ticket received | Ticket acknowledgment |
| Technician assigned | Assignment notification |
| Work starts | In Progress notification |
| Ticket becomes Pending | Pending notification |
| Ticket resolved | Resolution notification |
| Ticket closed | Closure notification |

Individual progress updates will be recorded in the dashboard. Sending a WhatsApp message for every progress update is optional and should be configurable.

All outbound messages shall be queued and their delivery status tracked.

Every outbound message shall follow the session-window and approved-template strategy defined in Section 11. The system must not assume that a successful API request guarantees delivery.

### FR-12: Reporting

The reporting module shall include:

1. Daily ticket summary.
2. Monthly ticket summary.
3. Ticket status report.
4. In-progress ticket report.
5. Technician workload report.
6. Department ticket volume report.
7. Ticket category report.
8. Response-time report.
9. Resolution-time report.
10. Overdue ticket report.

Filters shall include:

- Date range.
- Status.
- Priority.
- Department.
- Category.
- Assigned technician.

CSV or Excel export shall be supported in the initial or first reporting release. PDF export is an optional enhancement.

Response-time and resolution-time definitions must be documented. Time spent in Pending should be handled according to the department's agreed SLA rules.

### FR-13: User Management and Permissions

The system shall enforce role-based access.

Minimum roles:

- IT Administrator.
- IT Technician.

The initial release will not provide employee dashboard accounts.

All protected operations shall use server-side authentication and authorization policies.

### FR-14: Audit Trail

The system shall preserve a traceable history of:

- Ticket creation.
- Technician assignment.
- Status changes.
- Progress updates.
- Formal report generation.
- Relevant administrative changes.
- WhatsApp message processing and delivery results.

Audit records shall not expose API tokens, passwords, or unnecessary personal information.

---

## 5\. Ticket Lifecycle and Workflow

### 5.1 End-to-End Workflow

1. Employee sends an IT request through WhatsApp.
2. Meta Cloud API sends the webhook event to Laravel.
3. Laravel validates the webhook and persists the event.
4. A background job processes the message.
5. The sender is matched to an active employee record.
6. Laravel identifies whether the message belongs to a new or existing ticket.
7. A ticket is created or an existing ticket is updated.
8. The system stores the WhatsApp message and relevant metadata.
9. A dashboard notification is created.
10. An acknowledgment is queued for the employee.
11. IT Admin reviews and assigns the request.
12. The employee receives an assignment notification.
13. The technician begins work and changes the status to In Progress.
14. Laravel records the start time and creates the initial formal report.
15. The technician records progress entries.
16. The employee receives configured status notifications.
17. The technician records the resolution.
18. The ticket is resolved and later closed.
19. Ticket data is included in operational and management reports.

### 5.2 Status Transition Rules

The proposed initial transition rules are:

| Current Status | Allowed Next Status |
| --- | --- |
| New | Assigned, In Progress, Pending, Closed |
| Assigned | In Progress, Pending, Resolved, Closed |
| In Progress | Pending, Resolved, Closed |
| Pending | Assigned, In Progress, Resolved, Closed |
| Resolved | In Progress, Closed |
| Closed | None through ordinary updates |

Reopening a Closed ticket, if required, must be implemented as an explicit authorized action and recorded in the audit trail.

---

## 6\. Technical Architecture

### 6.1 Technology Stack

| Component | Technology |
| --- | --- |
| Backend | Laravel |
| Language | PHP |
| Database | MySQL |
| Dashboard | Blade, Livewire, Tailwind CSS |
| WhatsApp | Meta WhatsApp Business Platform Cloud API |
| Queue | Laravel Queue |
| Initial queue driver | Database |
| Optional queue driver | Redis |
| Initial live updates | Livewire polling |
| Optional real-time updates | Laravel Reverb/WebSockets |
| Authentication | Laravel-compatible authentication starter kit |
| Reports | Database queries and CSV/Excel export |
| Optional PDF reports | Server-side PDF generation |
| File storage | Private storage for attachments and reports |

The Laravel and PHP versions must be selected together based on supported versions at implementation time.

### 6.2 Application Structure

Recommended directories:

```
app/
├── Http/Controllers/
│   ├── WhatsAppWebhookController.php
│   ├── DashboardController.php
│   ├── TicketController.php
│   ├── TicketProgressController.php
│   └── TicketReportController.php
├── Jobs/
│   ├── ProcessWhatsAppWebhook.php
│   └── SendWhatsAppNotification.php
├── Models/
│   ├── Employee.php
│   ├── Ticket.php
│   ├── TicketMessage.php
│   ├── TicketProgressUpdate.php
│   ├── TicketProgressReport.php
│   ├── TicketStatusHistory.php
│   ├── DashboardNotification.php
│   └── WhatsAppWebhookEvent.php
├── Services/
│   ├── WhatsAppCloudApi.php
│   └── TicketService.php
└── Policies/
    └── TicketPolicy.php
```

Additional services, events, listeners, notification outbox components, and export classes may be added as the implementation develops.

### 6.3 Architectural Responsibilities

**WhatsAppWebhookController**

- Verify Meta webhook subscriptions.
- Validate webhook signatures.
- Persist incoming events safely.
- Return appropriate HTTP responses.

**ProcessWhatsAppWebhook**

- Process inbound messages.
- Identify employees.
- Deduplicate events.
- Create or update tickets.
- Process delivery-status events.
- Trigger downstream operations.

**TicketService**

- Centralize ticket creation.
- Enforce valid status transitions.
- Record status history.
- Create initial formal progress reports.
- Dispatch follow-up operations after successful database commits.

**WhatsAppCloudApi**

- Send text messages and templates.
- Send supported media messages when required.
- Handle API responses and errors.
- Return WhatsApp message IDs.

**DashboardController and dashboard views**

- Display ticket counters.
- Show unread notifications.
- Provide ticket search and filtering.
- Present ticket details, history and reports.

**TicketReportController**
- Generate filtered report data.
- Calculate operational metrics.
- Export reports.

---

## 7\. Database Design

The initial database shall include the following tables.

### 7.1 `users`

Stores authenticated IT administrators and technicians.

Core fields:

- `id`
- `name`
- `email`
- `password`
- `role` or role/permission relationships
- `created_at`
- `updated_at`

### 7.2 `employees`

Stores employee information.

Core fields:

- `id`
- `name`
- `whatsapp_number`
- `department_id` — foreign key to `departments` (Section 7.11)
- `company_id` — nullable foreign key to `companies` (Section 7.13)
- `position` — nullable job title, used on ticket requesters' details
- `location`
- `email`
- `is_active`
- `created_at`
- `updated_at`

The WhatsApp number shall be stored normalized in E.164 format and be unique among active records (Section 9.1).

### 7.3 `tickets`

Stores the primary ticket record.

Core fields:

- `id`
- `ticket_number`
- `employee_id`
- `company_id` — nullable foreign key to `companies` (Section 7.13), stamped from the requester's enrollment at creation
- `assigned_to`
- `subject`
- `description`
- `category`
- `priority`
- `status`
- `first_response_at`
- `started_at`
- `resolved_at`
- `closed_at`
- `created_at`
- `updated_at`

### 7.4 `ticket_messages`

Stores inbound and outbound WhatsApp messages and relevant communication history.

Core fields:

- `id`
- `ticket_id`
- `user_id`, nullable
- `direction`
- `channel`
- `message_type`
- `body`
- `whatsapp_message_id`
- `delivery_status`
- `is_internal`
- `metadata`
- `created_at`
- `updated_at`

### 7.5 `ticket_progress_updates`

Stores individual technician work entries.

Core fields:

- `id`
- `ticket_id`
- `user_id`
- `update_text`
- `next_action`
- `minutes_spent`
- `is_internal`
- `created_at`
- `updated_at`

### 7.6 `ticket_progress_reports`

Stores formal progress reports.

Core fields:

- `id`
- `ticket_id`
- `prepared_by`
- `report_number`
- `report_type`
- `title`
- `problem_summary`
- `work_performed`
- `findings`
- `next_steps`
- `reported_at`
- `document_path`
- `created_at`
- `updated_at`

A unique constraint shall be applied to `ticket_id` and `report_number`.

### 7.7 `ticket_status_histories`

Stores all ticket status transitions.

Core fields:

- `id`
- `ticket_id`
- `changed_by`
- `from_status`
- `to_status`
- `remark`
- `created_at`
- `updated_at`

### 7.8 `dashboard_notifications`

Stores persistent dashboard notifications with mandatory per-user read state.

Core fields:

- `id`
- `user_id` — the recipient
- `ticket_id`
- `type`
- `title`
- `message`
- `data`
- `read_at`, nullable
- `created_at`
- `updated_at`

Per-user read state is mandatory: multiple IT administrators exist (Section 3.2), and the required explicit read or acknowledgment action (FR-05) must be independent per administrator. When an event occurs, the system creates one notification row per authorized recipient. A unique constraint shall be applied to `user_id`, `type`, and `ticket_id` (for repeatable event types, include a distinguishing identifier such as the status-transition history ID in the constraint or `data`).

### 7.9 `whatsapp_webhook_events`

Stores incoming webhook events and processing outcomes.

Core fields:

- `id`
- `event_key`
- `event_type`
- `processing_status`
- `payload`
- `error_message`
- `processed_at`
- `created_at`
- `updated_at`

The `event_key` must be unique to prevent duplicate processing. It is the composite `{type}:{whatsapp_message_id}` value defined in Section 8.3.

### 7.10 `ticket_attachments`

Stores references to screenshots, photographs, and other ticket attachments.

Core fields:

- `id`
- `ticket_id`
- `ticket_message_id`, nullable
- `uploaded_by`, nullable
- `original_filename`
- `mime_type`
- `file_size`
- `storage_path`
- `created_at`
- `updated_at`

Files shall be stored privately. Download access must be authorized.

### 7.11 `departments` — Lookup Table

Stores the department list used by employee records and department-based reports (FR-12, Section 12.1).

Core fields:

- `id`
- `name` — unique
- `is_active`
- `created_at`
- `updated_at`

Departments are maintained by IT administrators. Report filters and groupings shall reference `department_id` so renamed or consolidated departments remain consistent in historical reporting.

### 7.12 `ticket_number_sequences` — Ticket Number Generation

Guarantees unique, gap-free-per-year ticket numbering under concurrent requests without table locks on `tickets`.

Core fields:

- `id`
- `year` — unique
- `next_value`
- `created_at`
- `updated_at`

Generation rule: within a transaction, lock the sequence row for the current year (`SELECT ... FOR UPDATE`), read `next_value`, increment it, and format the ticket number as `IT-YYYY-NNNNNN` (zero-padded to six digits). Insert a new sequence row with `next_value = 2` when the year rolls over. The unique constraints on `year` and on `tickets.ticket_number` make this safe under concurrent webhook processing.

### 7.13 `companies` — Lookup Table

Stores the company (tenant) list used for multi-company attribution of ticket requests. The system remains a single shared database: companies are a **tag, not an isolation boundary** — there are no per-company scoped views, separate numbers, or per-company ticket numbering.

Core fields:

- `id`
- `name` — unique
- `code` — nullable, unique short label (e.g. `ACME`), normalised to upper case; also accepted in employee CSV imports
- `email`
- `location`
- `is_active`
- `created_at`
- `updated_at`

Companies are maintained by IT administrators (Companies page). A ticket's `company_id` is never supplied by the sender — it is inherited from the enrolled requester (`employees.company_id`) when the ticket is created, for both the WhatsApp intake pipeline and the web submission form. `departments.company_id` and `users.company_id` are likewise optional tags. Deleting or deactivating a company never deletes historical rows (`ON DELETE SET NULL`), so reports keep their grouping via the fallback label "Unassigned".

---

## 8\. Webhook and Message Processing Design

### 8.1 Webhook Verification

Meta sends a verification request containing a challenge and verification token.

Laravel shall compare the provided token against the configured secret and return the challenge only when verification succeeds.

### 8.2 Incoming Webhook Validation

For incoming POST events, Laravel shall:

1. Read the original raw request body.
2. Validate `X-Hub-Signature-256` using the Meta app secret.
3. Reject invalid signatures.
4. Validate the expected webhook payload structure.
5. Persist the event to a durable inbox.
6. Dispatch processing after persistence.
7. Return an appropriate acknowledgment response.

### 8.3 Event Idempotency

Each incoming webhook shall be deduplicated by a composite `event_key` in `whatsapp_webhook_events`, formatted as `{type}:{whatsapp_message_id}` where `type` is `inbound` or `status`.

Delivery-status receipts reference the same WhatsApp message ID as the original outbound message, so a bare message ID must not be used as the key — it would silently drop status events as duplicates of the send.

Delivery-status events shall be processed idempotently, shall not create tickets, and shall update the existing outbound message record. An unexpected status type is stored as an ignored event.

The event processor must be recoverable if a worker crashes or an external API request fails.

### 8.4 Inbound Message Handling

For every inbound message:

1. Validate the sender and message ID.
2. Identify the employee.
3. Reject or route unknown senders to the registration flow (Section 9.2).
4. Determine whether the message is a new request or a follow-up.
5. Create or update the appropriate ticket.
6. Store the inbound message.
7. Create dashboard notifications for new tickets.
8. Queue employee acknowledgments when appropriate.

Media messages shall be handled separately, with authorized downloads and private storage.

### 8.5 Outbound Message Handling

Outbound notifications shall be queued.

The application shall store the outgoing message record and associate it with the ticket. When Meta returns a message ID, the application shall save it for later delivery-status updates.

The application shall distinguish between:

- Request accepted by the API.
- Message sent.
- Message delivered.
- Message read, where a read receipt is available.
- Message failed.

API errors and ambiguous timeouts must be handled without blindly duplicating messages.

### 8.6 Reliable Processing

Use a durable inbox/outbox pattern for production:

- Persist inbound events before acknowledging them.
- Process business logic in background jobs.
- Commit ticket data and notification intents transactionally.
- Dispatch outbound messages after commit.
- Retry eligible failures with bounded backoff.
- Record failed jobs and support controlled replay.
- Reconcile delivery receipts against stored outgoing messages.

---

## 9\. Employee Enrollment and Intake Rules

This section is normative. It defines how employees are registered, how unknown senders are handled, and what qualifies as a valid new IT request.

### 9.1 Employee Registration Sources

- Employees are pre-registered by IT Administrators through a dashboard Employee Directory page (see Section 10.7).
- Bulk CSV import shall be provided for the initial load and periodic HR sync. Import columns: name, whatsapp_number (E.164), department, company, position, location, email, is_active. The `company` column is optional (legacy files without it still import) and is matched against `companies` (Section 7.13) by name or code; the import shall fail with a report of unmapped companies rather than create ad-hoc values. Imported department names shall map to `departments` (Section 7.11); the import shall fail with a report of unmapped departments rather than create ad-hoc values.
- The `whatsapp_number` shall be stored normalized in E.164 format (for example `639171234567`) and must be unique across active employee records.
- Employees cannot self-register in the initial release.

### 9.2 Unknown-Sender Rule

When an inbound message arrives from a WhatsApp number with no matching active employee record:

- No ticket is created.
- The event is still persisted in `whatsapp_webhook_events` and the inbound content is not stored beyond the dedupe metadata, in line with Section 13.1 privacy guidance.
- The sender receives exactly one registration-guidance reply per number per 24 hours, instructing them to contact their IT department for enrollment. This reply is rate-limited and is sent only when no ticket is created.
- Further messages from the same number within the rate-limit window are recorded as ignored events without a reply.
- Unknown senders must never receive employee-specific ticket information. A `STATUS` command from an unknown number receives the same generic guidance.

### 9.3 Number Changes and Relink Requests

- When an employee changes WhatsApp numbers, an Administrator updates the `whatsapp_number` on the employee record through the Employee Directory.
- A registered employee may request a number relink from the new number by sending `REGISTER` or replying to the unknown-sender guidance. The system creates a relink request and a dashboard notification for Administrator approval; the new number is matched to the existing employee record only after approval.
- Relink requests shall never auto-merge records or expose ticket data before approval.
- Deactivating an employee stops ticket creation and outbound notifications for that number. Existing tickets remain intact.

### 9.4 Message Classification Order

Inbound messages from an active registered employee shall be classified in this exact order, stopping at the first match:

1. **Command** – `NEW TICKET [description]`, `STATUS <ticket-number>`, `CLOSE <ticket-number>`. The command grammar is fixed and case-insensitive.
2. **Explicit ticket reference** – a message body containing a valid `IT-YYYY-NNNNNN` number owned by the sender is attached to that ticket as a follow-up message.
3. **Reply/quote threading** – a message whose WhatsApp `context.id` references an outbound notification for one of the sender's tickets is attached to that ticket.
4. **Recent-conversation auto-attach** – if the sender has exactly one ticket in New, Assigned, In Progress, or Pending status and the message arrives within a configurable follow-up window (default: 60 minutes since the ticket's last inbound message), it is attached as additional information and no new ticket is created.
5. **Candidate new request** – anything else is evaluated against the Section 9.5 validity rules.

If more than one ticket matches a reference or thread, the system replies with a numbered list of the sender's matching open tickets and waits for a selection. This selection dialog shall not auto-attach the message to the most recently created ticket.

### 9.5 Validity Rules for a New IT Request

A candidate message shall create exactly one ticket only when all of the following hold:

- The sender matches an active employee record (Section 9.1).
- No Section 9.4 rule above matched.
- The message contains non-empty content: text of at least 5 characters after trimming, or any supported media attachment (image, document, audio, video, sticker).
- The message is not a pure greeting, acknowledgement, or emoji-only text (for example "hi", "thanks", "ok", "😂"). Such messages receive a short usage-guidance reply and no ticket is created.
- The sender has no auto-created ticket within the duplicate-inquiry window (default: 10 minutes). A message inside this window is attached to that ticket instead of creating a duplicate.

A message that satisfies the rules but appears non-IT shall still create a ticket: classification of request validity beyond these mechanical rules remains an Administrator action. Administrators may close such tickets with an `Invalid` status remark, and no acknowledgment or notification shall be queued for a ticket created and then closed as invalid in one action.

### 9.6 Acknowledgment Gate

- Every validated new request produces exactly one ticket and one queued acknowledgment (FR-04).
- The acknowledgment is sent only when an active session window exists or an approved template is available (Section 11). Because the employee's inbound message opens the 24-hour window, acknowledgments are expected to be within-window in normal operation.

---

## 10\. Dashboard Page Requirements

### 10.1 Main Dashboard

The dashboard shall display:

- New ticket count.
- Assigned ticket count.
- In-progress ticket count.
- Pending ticket count.
- Resolved ticket count.
- Closed ticket count.
- Unread notifications.
- Latest tickets.
- Ticket priority.
- Assigned technician.
- Ticket creation timestamp.

The initial dashboard may poll every 5–10 seconds. A WebSocket implementation may replace polling later.

### 10.2 Ticket List

The ticket list shall support:

- Search by ticket number and subject.
- Search by employee where authorized.
- Filter by status.
- Filter by priority.
- Filter by category.
- Filter by technician.
- Filter by date.
- Pagination.
- Ticket detail navigation.

### 10.3 Ticket Detail

The ticket detail page shall show:

- Ticket number and status.
- Employee details.
- Original request.
- WhatsApp message history.
- Attachments.
- Assigned technician.
- Category and priority.
- Status history.
- Technician progress history.
- Formal progress reports.
- Resolution details.
- Relevant notification and delivery records.

### 10.4 Ticket Assignment and Update Page

The page shall allow authorized users to:

- Assign or reassign a technician.
- Change category and priority.
- Change ticket status.
- Enter status remarks.
- Record resolution details.
- View previous assignment and status history.

### 10.5 Progress Entry Page

The page shall allow technicians to record:

- Work performed.
- Findings.
- Next steps.
- Time spent.
- Internal or employee-visible notes.

### 10.6 Reports Page

The reports page shall display filtered ticket summaries and allow authorized users to export data.

### 10.7 Employee Directory (Registration Management)

IT Administrators shall be able to:

- Create, edit, and deactivate employee records (name, E.164 WhatsApp number, department, company, position, location, email, active flag). The company (Section 7.13) is required on the form so every request stays attributable.
- Bulk-import employees from CSV.
- View each employee's ticket history and last message date.
- Approve or reject number-relink requests from the dashboard notification feed (Section 9.3).

Technicians shall not access the Employee Directory unless explicitly authorized.

---

## 11\. Employee WhatsApp Notification and Template Strategy

This section is normative for all outbound WhatsApp messages (FR-04, FR-06, FR-11) and is referenced throughout this document as the messaging-window and template strategy.

### 11.1 Session Window Rules

- The WhatsApp Cloud API permits free-form session messages only within 24 hours of the employee's last inbound message.
- The employee's initial request opens the window, so acknowledgments and early status notifications are normally within-window.
- Every inbound employee message reopens the 24-hour window.
- Notifications for later transitions (Pending, Resolved, Closed, assignment changes) may fall outside the window and must use approved templates.
- The system shall not assume that a successful API response means the message was delivered (see Section 8.5 delivery tracking).

### 11.2 Outbound Decision Rule

At dispatch time the queue job shall evaluate:

1. An active 24-hour session window exists for the employee number → send a free-form session message.
2. No active window → send the mapped approved template with dynamic parameters (Section 11.3).
3. The template send fails or no template is approved yet → record the notification as `failed_window_constraint`, keep the persisted notification intent, and schedule a bounded retry. Ambiguous timeouts must not be blindly re-sent.

### 11.3 Approved Template Inventory

The initial release shall submit the following utility templates to Meta for approval:

| Template | Used for |
| --- | --- |
| `ticket_acknowledgment` | FR-04 ticket acknowledgment fallback |
| `ticket_assigned` | FR-06 assignment notification |
| `ticket_in_progress` | Work-start notification |
| `ticket_pending` | Pending-status notification |
| `ticket_resolved` | Resolution notification |
| `ticket_closed` | Closure notification |
| `ticket_status_reply` | Response to a `STATUS <ticket-number>` command |
| `unregistered_guidance` | Unknown-sender registration guidance (Section 9.2) |

Template requirements:

- Template names, language code, category, approval status, and body parameter mapping shall be stored in a `whatsapp_templates` configuration table and referenced by notification type.
- Dynamic values (ticket number, subject, technician name, status) are passed as template parameters. Subjects must be sanitized to fit approved parameter formats.
- Template submissions shall occur early in Phase 3 because Meta review can take several days. Until a template is approved, out-of-window notifications for that event type follow the Section 11.2 failure path.

### 11.4 Delivery-State Tracking

The delivery states defined in Section 8.5 (accepted, sent, delivered, read, failed) apply equally to session messages and template sends, and shall be reconciled against webhook status receipts.

---

## 12\. Reporting and Performance Metrics

### 12.1 Operational Reports

The system shall generate:

- Tickets received by day and month.
- Tickets by current status.
- Tickets by priority.
- Tickets by department.
- Tickets by company.
- Tickets by category.
- Tickets assigned to each technician.
- Tickets currently in progress.
- Tickets pending for extended periods.
- Resolved and closed tickets.

### 12.2 Performance Metrics

Recommended metrics:

**First response time**

Time between ticket receipt and the first qualifying response from IT.

**Resolution time**

Time between ticket receipt and resolution.

**Open ticket age**

Time between ticket creation and the current time for unresolved tickets.

**Technician workload**

Number of tickets assigned to each technician, with optional workload weighting.

**Overdue tickets**

Tickets that exceed an agreed service target.

The department must define business hours, holidays, priority targets, and whether Pending time is excluded before using SLA metrics for performance evaluation.

### 12.3 Export

The first reporting release shall support CSV or Excel export. PDF report generation may be added as a separate enhancement.

Exports shall respect the same authorization and filtering rules as the dashboard.

---

## 13\. Non-Functional Requirements

### 13.1 Security

- Use HTTPS.
- Store credentials and API tokens in environment configuration or an appropriate secrets manager.
- Never expose Meta access tokens to the browser.
- Validate incoming webhook signatures.
- Use server-side authorization policies.
- Protect private attachments.
- Apply request validation and output escaping.
- Keep audit logs for important actions.
- Avoid logging message contents or personal information unnecessarily.

### 13.2 Reliability

- Use database transactions for ticket and status changes.
- Apply unique constraints to ticket numbers and message IDs.
- Make webhook processing idempotent.
- Persist notification intents.
- Retry recoverable background-job failures.
- Monitor queue failures and API errors.
- Back up the database and attachments.

### 13.3 Performance

- Use database indexes for common ticket filters.
- Apply pagination to ticket lists.
- Use eager loading to avoid excessive database queries.
- Process media and outgoing messages asynchronously.
- Keep webhook request handling short.
- Use queue workers for long-running tasks.

### 13.4 Maintainability

- Keep business logic in services rather than duplicating it across controllers.
- Use separate jobs for background processing.
- Use explicit status-transition rules.
- Maintain automated tests.
- Document environment configuration and deployment procedures.

### 13.5 Privacy and Retention

The organization shall establish retention periods for WhatsApp messages, attachments, ticket histories, and formal reports.

Access to employee information and internal IT notes shall be limited to authorized personnel. Deletion and retention procedures must follow the organization's applicable privacy and records-management policies.

---

## 14\. Implementation Plan

### Phase 1: Requirements and Environment Setup

Tasks:

- Confirm ticket categories and priority levels.
- Confirm employee registration and identification rules (Section 9).
- Draft and approve outbound notification template content (Section 11).
- Finalize status-transition rules.
- Define roles and permissions.
- Select supported Laravel and PHP versions.
- Configure MySQL, authentication, and queue infrastructure.
- Establish source control and environment configuration.

Deliverables:

- Approved requirements.
- Working Laravel project.
- Initial authentication and database setup.

### Phase 2: Database and Ticket Core

Tasks:

- Create migrations and Eloquent models.
- Implement the departments lookup table and ticket-number sequence mechanism (Sections 7.11 and 7.12).
- Implement employee registration.
- Implement ticket number generation.
- Implement ticket creation and status transitions.
- Implement status history.
- Implement authorization policies.

Deliverables:

- Working ticket-management foundation.
- Database schema and model relationships.
- Automated tests for ticket creation and transitions.

### Phase 3: WhatsApp Cloud API Integration

Tasks:

- Configure the Meta application and WhatsApp Business account.
- Submit notification templates to Meta for approval (Section 11); template review can take several days and is on the critical path for Phase 5.
- Configure webhook verification.
- Implement signature validation.
- Persist incoming webhook events.
- Process inbound messages.
- Match employee numbers.
- Implement the unknown-sender registration flow (Section 9.2).
- Create tickets automatically.
- Send acknowledgments.
- Process delivery-status updates.
- Implement media handling.

Deliverables:

- Working WhatsApp-to-ticket workflow.
- Duplicate-message protection.
- Error logging and recoverable event processing.

### Phase 4: Dashboard and Live Notifications

Tasks:

- Implement dashboard layout.
- Add ticket status counters.
- Add recent ticket list.
- Add unread notification feed.
- Add search and filtering.
- Implement polling or Reverb-based updates.
- Add notification read-state management.

Deliverables:

- Operational IT Admin dashboard.
- Live or near-real-time new-ticket notifications.

### Phase 5: Assignment, Progress and Status Notifications

Tasks:

- Implement technician assignment.
- Implement status-transition controls.
- Implement progress entry forms.
- Generate initial formal reports when work starts.
- Add employee WhatsApp notifications.
- Add internal and employee-visible note handling.
- Track outgoing message delivery status.

Deliverables:

- Complete ticket lifecycle.
- Technician progress history.
- Formal in-progress reports.
- Employee notification workflow.

### Phase 6: Reporting

Tasks:

- Implement report filters.
- Build daily and monthly summaries.
- Build status and workload reports.
- Implement response and resolution metrics.
- Implement CSV/Excel exports.
- Add PDF reports if required.

Deliverables:

- Operational reporting module.
- Exportable management reports.

### Phase 7: Testing, Security and Deployment

Tasks:

- Test duplicate webhook deliveries.
- Test failed and retried jobs.
- Test invalid webhook signatures.
- Test status transitions.
- Test WhatsApp delivery failures.
- Test user permissions.
- Test reports against known data.
- Configure backups, monitoring and HTTPS.
- Conduct user acceptance testing.
- Deploy to the production environment.

Deliverables:

- Tested production release.
- Deployment and recovery documentation.
- IT administrator and technician instructions.

---

## 15\. Testing and Acceptance Criteria

### 15.1 WhatsApp Integration Tests

- Valid webhook verification succeeds.
- Invalid verification requests are rejected.
- Invalid signatures are rejected.
- Incoming text messages are processed.
- Unsupported message types are handled safely.
- Unknown employees are routed appropriately.
- Duplicate webhook deliveries do not create duplicate tickets.
- Delivery receipts update the correct outgoing message.

### 15.2 Ticket Tests

- New tickets receive unique ticket numbers.
- Ticket creation records the employee and timestamp.
- Assignment changes are recorded.
- Invalid status transitions are rejected.
- Status history records all successful transitions.
- Draft-formal initial reports are created once when work starts and are finalized by the technician's first progress entry.
- Progress entries preserve their authors and timestamps.
- Resolved tickets retain their resolution details.

### 15.3 Dashboard Tests

- New tickets appear without manual refresh.
- Counters reflect the database state.
- Unread notifications remain unread until acknowledged.
- Filters return correct results.
- Pagination works.
- Unauthorized users cannot access restricted functions.

### 15.4 Notification Tests

- Ticket acknowledgments are queued after ticket creation.
- Important status changes trigger the appropriate notification workflow.
- Failed sends are recorded.
- Eligible failures can be retried safely.
- Delivery receipts update the relevant message.
- Internal notes are never sent to employees.

### 15.5 Reporting Tests

- Date filters return the expected tickets.
- Status counts match the underlying records.
- Technician workload figures are accurate.
- Response and resolution calculations follow documented rules.
- Exports respect filters and access permissions.

---

## 16\. Risks and Mitigation

| Risk | Mitigation |
| --- | --- |
| Duplicate webhook delivery | Unique message IDs and idempotent event processing |
| Lost notification after a database commit | Durable outbox and recoverable queue jobs |
| Incorrect employee identification | Registered employee directory and verification workflow |
| Ambiguous follow-up messages | Explicit ticket reference or guided selection |
| WhatsApp template restrictions | Approved templates and messaging-window checks |
| Meta template approval delays | Submit templates early in Phase 3; keep in-window free-form fallbacks where possible |
| Unknown-sender spam | Rate-limited guidance replies only; no ticket or data exposure |
| Media download failures | Retryable media jobs and controlled error handling |
| Multiple technicians updating the same ticket | Database transactions and status-transition validation |
| Unauthorized access | Authentication, policies and private storage |
| Inaccurate performance reports | Documented metric definitions and tested report queries |
| Service outage or data loss | Monitoring, backups and recovery procedures |

---

## 17\. Out of Scope for the Initial Release

Unless separately approved, the following are not required for the initial MVP:

- A separate employee web portal.
- A native mobile application.
- AI-based ticket categorization.
- Automatic troubleshooting and AI responses.
- Email or SMS notifications for administrators.
- Complex SLA escalation workflows.
- Multi-company or multi-tenant support.
- Advanced asset inventory and CMDB integration.
- Automatic remote support or device management.
- Advanced PDF report customization.

These can be considered for future phases after the core workflow is stable.

---

## 18\. Deployment Requirements

Before production deployment, the environment shall have:

- A supported PHP and Laravel installation.
- MySQL database.
- HTTPS-enabled public webhook endpoint.
- Meta WhatsApp Business Platform configuration.
- Valid access token and phone number ID.
- Configured queue workers.
- Database migrations and backup procedures.
- Scheduler and failed-job monitoring where required.
- Restricted environment secrets.
- Authentication and role-based access.
- Logging and operational monitoring.

Production secrets shall never be stored in public repositories or exposed in browser code.

---

## 19\. Final Deliverables

The project shall deliver:

1. Laravel application source code.
2. Database migrations and Eloquent models.
3. WhatsApp Cloud API integration.
4. Webhook event processing and duplicate protection.
5. Automatic ticket creation.
6. Employee Directory and enrollment management (Sections 9 and 10.7).
7. IT Admin dashboard.
8. Live or near-real-time dashboard notifications.
9. Ticket assignment and status management.
10. Technician progress history.
11. Initial formal in-progress report generation.
12. Employee WhatsApp notifications with approved templates and session-window fallback (Section 11).
13. Reporting and data export module.
14. Authentication and authorization policies.
15. Automated tests.
16. Environment configuration guide.
17. Deployment and backup documentation.
18. User guide for IT administrators and technicians.

---

## 20\. Definition of Done

The MVP is complete when an employee can send an IT request through WhatsApp and the request can be managed end-to-end through the Laravel application.

The full validated workflow shall be:

**WhatsApp submission → Automatic ticket creation → Dashboard notification → Technician assignment → In Progress → Formal progress report → Technician progress history → Status notifications → Resolution → Closure → Management reporting.**

The system must preserve ticket history, process webhook events safely, handle message-delivery failures, and enforce appropriate access permissions.

---

## 21\. Final Technical Decision Summary

| Decision | Approved Approach |
| --- | --- |
| Employee submission | WhatsApp |
| WhatsApp integration | Meta WhatsApp Cloud API |
| Backend | Laravel |
| Database | MySQL |
| Dashboard | Blade, Livewire and Tailwind CSS |
| Admin notification | Live dashboard only |
| Employee notification | WhatsApp for important status changes |
| Outbound messaging | Section 11 session-window and approved-template strategy |
| Ticket creation | Automatic from incoming messages |
| Ticket status tracking | New, Assigned, In Progress, Pending, Resolved, Closed |
| Progress tracking | Timestamped technician updates |
| Formal reporting | Draft report on first In Progress transition; finalized by the technician's first progress entry |
| Management reporting | Dashboard reports and CSV/Excel export |
| Queue processing | Laravel Queue with durable event processing |
| Initial live updates | Livewire polling |
| Optional real-time enhancement | Laravel Reverb/WebSockets |
| Security | Authentication, policies, webhook signature validation and private storage |

**Document Status:** Approved for technical implementation planning.
