# laravel-approval-workflow

[![CI](https://github.com/hiralrajgor/laravel-approval-workflow/actions/workflows/ci.yml/badge.svg)](https://github.com/hiralrajgor/laravel-approval-workflow/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**[Live Demo →](https://hiralrajgor.github.io/approval-workflow/)**

A production-grade **document approval workflow engine** built with Laravel 11.

Configurable multi-step approval chains (draft → review → approve → publish), async email notifications, role-based access control, immutable audit trail, and a Swagger-documented REST API.

---

## Why This Exists

This project comes directly from building approval-chain systems in large-scale ERP environments (port operations, logistics, compliance). The patterns here — state machines, event-driven notifications, RBAC, audit trails — are the same patterns that run in production at scale. This codebase is a clean, standalone extraction of those ideas.

---

## The Workflow

```
                   ┌─────────────────────────────────────────────────────┐
                   │                                                     │
  [Author]         │  DRAFT ──────────► PENDING                         │
                   │                       │                             │
  [Reviewer]       │               ┌───────┴────────┐                   │
                   │               ▼                ▼                   │
                   │           IN_REVIEW ──► REJECTED ──► DRAFT (revise)│
                   │               │                                     │
  [Approver]       │               ▼                                     │
                   │           APPROVED                                  │
                   │               │                                     │
  [Publisher]      │               ▼                                     │
                   │           PUBLISHED  (terminal — no further changes)│
                   └─────────────────────────────────────────────────────┘
```

Each arrow is an **explicit, enforced transition**. The state machine guard rejects any attempt to skip a step or move backwards unless the graph permits it.

---

## Roles and Permissions

| Role      | Can do                                              |
|-----------|-----------------------------------------------------|
| Author    | Create/edit drafts, submit for review               |
| Reviewer  | Pick up pending docs, start review, reject          |
| Approver  | Approve or reject documents under review            |
| Publisher | Publish approved documents                          |
| Admin     | All of the above, plus bypass ownership checks      |

---

## Tech Stack

- **Laravel 11** — framework
- **PHP 8.3** — leverages enums, readonly properties, named arguments
- **Laravel Sanctum** — API token authentication
- **Laravel Queues** — async mail dispatch (`database` driver by default, swap to Redis/SQS for production)
- **PHPUnit** — feature + unit tests
- **L5-Swagger / darkaonline** — OpenAPI 3.0 docs generated from annotations

---

## Project Structure

```
app/
├── Enums/
│   ├── DocumentStatus.php      ← State machine graph lives here
│   └── UserRole.php            ← Role-to-transition permission map
├── Events/
│   └── DocumentTransitioned.php
├── Listeners/
│   └── SendTransitionNotification.php  ← Queued, retryable
├── Mail/
│   ├── DocumentSubmittedMail.php
│   ├── DocumentApprovedMail.php
│   ├── DocumentRejectedMail.php
│   └── DocumentPublishedMail.php
├── Models/
│   ├── Document.php            ← Uses HasAuditLog trait
│   ├── DocumentTransition.php  ← Immutable ledger row
│   ├── AuditLog.php            ← Generic polymorphic log
│   └── User.php
├── Policies/
│   └── DocumentPolicy.php      ← RBAC gate for all document actions
├── Services/
│   └── WorkflowService.php     ← Single entry-point for all transitions
├── Traits/
│   └── HasAuditLog.php         ← Mix into any model for audit trail
└── Http/
    ├── Controllers/Api/
    │   └── DocumentController.php
    ├── Requests/
    │   ├── StoreDocumentRequest.php
    │   ├── UpdateDocumentRequest.php
    │   └── TransitionDocumentRequest.php
    └── Resources/
        ├── DocumentResource.php
        ├── DocumentTransitionResource.php
        ├── AuditLogResource.php
        └── UserResource.php

database/
├── migrations/
│   ├── ..._create_users_table.php
│   ├── ..._create_documents_table.php
│   ├── ..._create_document_transitions_table.php   ← append-only ledger
│   └── ..._create_audit_logs_table.php             ← polymorphic audit
├── factories/
│   ├── DocumentFactory.php     ← state-scoped factory methods
│   └── UserFactory.php         ← role-scoped factory methods
└── seeders/
    └── DatabaseSeeder.php      ← one user per role, sample documents

tests/
├── Feature/
│   └── WorkflowTest.php        ← 15 tests covering guards, events, HTTP
└── Unit/
    └── DocumentStatusTest.php  ← pure enum/state-machine unit tests
```

---

## Quick Start

### 1. Clone & install

```bash
git clone https://github.com/your-username/laravel-approval-workflow.git
cd laravel-approval-workflow
composer install
```

### 2. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```env
DB_CONNECTION=sqlite          # simplest for local dev
DB_DATABASE=/absolute/path/to/database.sqlite

QUEUE_CONNECTION=database     # or redis for production

MAIL_MAILER=log               # writes emails to storage/logs — no SMTP needed locally
```

### 3. Migrate and seed

```bash
touch database/database.sqlite   # if using SQLite
php artisan migrate --seed
```

Seeded credentials:

| Role      | Email                   | Password |
|-----------|-------------------------|----------|
| Author    | author@example.com      | password |
| Reviewer  | reviewer@example.com    | password |
| Approver  | approver@example.com    | password |
| Publisher | publisher@example.com   | password |
| Admin     | admin@example.com       | password |

### 4. Start the server

```bash
php artisan serve
```

### 5. Start the queue worker (required for mail)

```bash
php artisan queue:work --tries=3
```

### 6. Run the tests

```bash
php artisan test
# or
php artisan test --coverage
```

---

## API Reference

### Authentication

All endpoints require a Bearer token. Get one via Sanctum:

```http
POST /api/sanctum/token
Content-Type: application/json

{
  "email": "author@example.com",
  "password": "password",
  "device_name": "postman"
}
```

Use the returned token as:
```
Authorization: Bearer <token>
```

---

### Endpoints

#### Documents

```
GET    /api/documents                  List all documents (filterable by ?status=)
POST   /api/documents                  Create a new draft
GET    /api/documents/{id}             Get document detail (includes allowed_transitions)
PUT    /api/documents/{id}             Update draft or rejected document
DELETE /api/documents/{id}             Soft-delete a draft
```

#### Workflow

```
POST   /api/documents/{id}/transition  Move document to a new status
GET    /api/documents/{id}/history     Full transition ledger
GET    /api/documents/{id}/audit       Generic audit log
```

---

### Transition the status of a document

```http
POST /api/documents/1/transition
Authorization: Bearer <token>
Content-Type: application/json

{
  "status": "pending",
  "comment": "Ready for review — all figures verified."
}
```

**Response (200 OK):**
```json
{
  "data": {
    "id": 1,
    "title": "Q4 Financial Report",
    "status": {
      "value": "pending",
      "label": "Pending Review",
      "color": "yellow"
    },
    "allowed_transitions": [
      { "value": "in_review", "label": "In Review" },
      { "value": "rejected",  "label": "Rejected" }
    ],
    "latest_transition": {
      "from_status": { "value": "draft", "label": "Draft" },
      "to_status":   { "value": "pending", "label": "Pending Review" },
      "actor": { "name": "Alice Author", "role": { "value": "author" } },
      "comment": "Ready for review — all figures verified.",
      "created_at": "2024-11-14T09:32:00.000000Z"
    }
  }
}
```

**Error — invalid transition (422):**
```json
{
  "message": "Cannot transition from [Draft] to [Approved]. Allowed next states: [Pending Review].",
  "errors": {
    "status": ["Cannot transition from [Draft] to [Approved]..."]
  }
}
```

**Error — wrong role (403):**
```json
{
  "message": "Your role [Author] is not permitted to move a document to [Approved]."
}
```

---

### Full walkthrough via curl

```bash
# 1. Get author token
TOKEN=$(curl -s -X POST http://localhost:8000/api/sanctum/token \
  -H "Content-Type: application/json" \
  -d '{"email":"author@example.com","password":"password","device_name":"cli"}' \
  | jq -r '.token')

# 2. Create a draft
DOC_ID=$(curl -s -X POST http://localhost:8000/api/documents \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"HSE Policy Update","body":"Full body content here..."}' \
  | jq -r '.data.id')

# 3. Submit for review
curl -s -X POST http://localhost:8000/api/documents/$DOC_ID/transition \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"pending","comment":"Ready for review"}' | jq .

# 4. Reviewer picks it up (swap token to reviewer's)
REVIEWER_TOKEN=$(curl -s -X POST http://localhost:8000/api/sanctum/token \
  -H "Content-Type: application/json" \
  -d '{"email":"reviewer@example.com","password":"password","device_name":"cli"}' \
  | jq -r '.token')

curl -s -X POST http://localhost:8000/api/documents/$DOC_ID/transition \
  -H "Authorization: Bearer $REVIEWER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"in_review"}' | jq .

# 5. View full history
curl -s http://localhost:8000/api/documents/$DOC_ID/history \
  -H "Authorization: Bearer $TOKEN" | jq .
```

---

## Swagger / OpenAPI

Generate the spec:
```bash
php artisan l5-swagger:generate
```

Then open: [http://localhost:8000/api/documentation](http://localhost:8000/api/documentation)

---

## Key Design Decisions

See **[DECISIONS.md](./DECISIONS.md)** for full architectural decision records. Summary:

| Decision | Choice | Why |
|---|---|---|
| State machine | PHP Enum with `allowedTransitions()` | Single source of truth for the graph; unit-testable without the framework |
| Notifications | Events + queued Listeners | Decoupled side-effects; HTTP response not blocked by SMTP |
| Audit trail | Two tables (`transitions` + `audit_logs`) | Typed domain ledger vs generic compliance log — different query patterns |
| Authorization | Policy (HTTP layer) + Service (defence-in-depth) | Service is called from jobs/CLI too, can't assume HTTP context |
| Deletions | Soft-delete only via API | Published documents must never be lost |

---

## Limitations

These are known, intentional scope boundaries — not bugs:

1. **Linear workflow only.** The state machine is a directed graph with a single active state. Parallel approval ("all three department heads must approve") is not supported. This would require a `document_approvers` pivot table and consensus logic.

2. **No SLA / deadline tracking.** There is no `due_at` field or overdue notification. Add a `due_at` column to `document_transitions` and a scheduled job (`php artisan schedule:run`) to fire reminders.

3. **No multi-tenancy.** All documents and users share a single namespace. Add a `team_id` or `organisation_id` foreign key and scope all queries accordingly.

4. **Workflow is code-driven.** Adding a new step requires a code change and deployment. A v2 could store the step graph as JSON in `workflow_config` and drive transitions dynamically.

5. **Queue required for mail.** If no queue worker is running, notifications are silently dropped. In low-traffic environments, set `QUEUE_CONNECTION=sync` in `.env` to deliver mail synchronously (at the cost of slower HTTP responses).

6. **No file attachments.** Documents have a `body` text field only. Attach files via a separate `document_attachments` table using Laravel's Storage facade.

7. **Single reviewer/approver.** Any user with the Reviewer role can pick up any pending document. A dedicated "assignment" model would allow specific reviewers to be assigned to specific documents.

---

## Future Roadmap

- [ ] **Configurable multi-step chains** — drive the workflow graph from `workflow_config` JSON, not code
- [ ] **Parallel approval** — require N approvers before advancing
- [ ] **SLA tracking** — `due_at` per step, overdue reminders via scheduled jobs
- [ ] **Webhook delivery** — fire HTTP callbacks on transitions (Zapier-style integrations)
- [ ] **Document versioning** — track body content diffs across revisions
- [ ] **Bulk operations** — approve/reject multiple documents in one API call
- [ ] **Dashboard** — read-only Vue/React UI showing pipeline health, bottleneck analysis

---

## Running Tests

```bash
# All tests
php artisan test

# With coverage report
php artisan test --coverage --min=80

# Unit only (zero DB, runs in milliseconds)
php artisan test --testsuite=Unit

# Feature only
php artisan test --testsuite=Feature

# Single test method
php artisan test --filter it_rejects_an_invalid_state_skip
```

### What the tests cover

**Unit (`DocumentStatusTest`):**
- Every valid edge in the state machine graph
- Every invalid edge is rejected
- Terminal state (PUBLISHED) has no outgoing edges
- All statuses have labels and colors

**Feature (`WorkflowTest`):**
- Full lifecycle: draft → pending → in_review → approved → published
- Rejection and revision cycle
- State machine guard rejects skipped steps
- State machine guard rejects backward transitions
- RBAC: wrong-role transitions are rejected
- Admin bypasses role restrictions
- `DocumentTransitioned` event is fired with correct payload
- Submission queues mail to reviewers
- Approval queues mail to author
- Rejection queues mail to author with comment
- Transition record is written to the ledger table
- Audit log entry is created
- API returns 403 for wrong role
- API returns 422 for invalid state
- API resource includes `allowed_transitions`

---

## Author

Built by [Your Name](https://github.com/your-username) — 8 years building backend systems in Laravel, primarily in ERP, logistics, and compliance domains.

This project reflects patterns extracted from production approval workflows at enterprise scale. The state machine, event architecture, and dual-audit-table design are battle-tested approaches, not academic exercises.

---

## License

MIT
