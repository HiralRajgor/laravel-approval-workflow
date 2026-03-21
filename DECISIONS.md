# DECISIONS.md — Architectural Decision Records

This file explains **why** key design choices were made.
A senior reviewer reading this knows immediately they're looking at someone who thinks about trade-offs, not just solutions.

---

## ADR-001: State Machine via Enum, Not a Status Column with Booleans

**Status:** Accepted

### Context
The simplest approach to workflow state is a `status` string column with ad-hoc checks scattered across controllers:
```php
if ($document->status === 'draft' && $user->role === 'author') { ... }
```

### Decision
Use a PHP 8.1 `BackedEnum` (`DocumentStatus`) that encodes the **transition graph** directly as data:

```php
public function allowedTransitions(): array
{
    return match($this) {
        self::DRAFT   => [self::PENDING],
        self::PENDING => [self::IN_REVIEW, self::REJECTED],
        // ...
    };
}
```

### Consequences
**✅ Good:**
- The transition graph is a single, auditable source of truth. Adding or removing an edge is a one-line change.
- Invalid transitions are structurally impossible — `canTransitionTo()` is called before any DB write.
- The enum is unit-testable with zero framework overhead (see `DocumentStatusTest`).
- `allowed_transitions` is returned in every API response, so the frontend never needs to duplicate the graph logic.

**⚠️ Trade-off:**
- A more complex workflow (parallel approvals, SLA timers, conditional branching) would outgrow this enum-based approach. At that point, a dedicated state machine library (`spatie/laravel-model-states`) or a workflow engine (Temporal, AWS Step Functions) is the right call. This is documented in `LIMITATIONS.md`.

---

## ADR-002: Events + Queued Listeners Over Direct Service Calls for Notifications

**Status:** Accepted

### Context
The naive approach calls `Mail::send()` directly inside the transition service:
```php
public function approve(Document $doc, User $actor): Document
{
    $doc->update(['status' => 'approved']);
    Mail::send(new DocumentApprovedMail($doc, $actor)); // ← blocks the response
    return $doc;
}
```

### Decision
Fire a `DocumentTransitioned` event. A `SendTransitionNotification` listener (implements `ShouldQueue`) handles the mail asynchronously.

### Consequences
**✅ Good:**
- The HTTP response returns in ~5ms regardless of SMTP latency.
- Adding Slack notifications, webhooks, or audit-to-S3 means adding a new listener — the `WorkflowService` is never touched.
- Failed mail delivery doesn't roll back a valid state transition. The job enters `failed_jobs` and can be retried without business-logic side effects.
- Listener failures are isolated — one bad listener doesn't affect others for the same event.

**⚠️ Trade-off:**
- Requires a running queue worker (`php artisan queue:work`). In environments without a worker, notifications silently drop. Document this in the deployment guide.
- Debugging async flows is harder than synchronous calls. Laravel Telescope is the recommended tool for tracing event → listener → mail chains in development.

**Why not `Mail::later()`?**
`Mail::later()` is syntactic sugar around a queued job, but it bypasses the event system. Using events means the notification side-effect is decoupled from the service — other listeners (Slack, webhooks) don't require changes to the mail logic.

---

## ADR-003: Two Separate Audit Tables — `document_transitions` and `audit_logs`

**Status:** Accepted

### Context
Some projects store everything in a single `activity_log` table (as `spatie/laravel-activitylog` does). Why two tables here?

### Decision
- **`document_transitions`**: Typed, structured, domain-specific. Captures `from_status → to_status` with full enum casting. This is the **workflow ledger** — queried for history timelines, SLA reports, and analytics.
- **`audit_logs`**: Polymorphic, generic. Captures any event on any model: `created`, `updated`, `deleted`, `login`, `export`. This is the **compliance log** — queried by admins to answer "who touched what, when."

### Consequences
**✅ Good:**
- Each table has a clear, single responsibility.
- `document_transitions` can be indexed and queried efficiently for workflow analytics without JSON parsing.
- `audit_logs` stays generic enough to be reused for Users, Settings, or any future model by just adding `use HasAuditLog`.

**⚠️ Trade-off:**
- A successful transition writes to both tables inside the same DB transaction, adding ~1ms overhead. For this use case, that's acceptable.
- Two tables to maintain vs one. Accepted given the clear separation of concerns.

---

## ADR-004: Policy Layer + Service Layer — Defence in Depth

**Status:** Accepted

### Context
Authorization could live in one place. Why split it across `DocumentPolicy` and `WorkflowService`?

### Decision
- **Policy (`DocumentPolicy`)**: Answers "can this user attempt this action?" — checked at the HTTP layer by the controller before any business logic runs.
- **Service (`WorkflowService`)**: Also checks role permissions as a safety net. The service is called directly in tests, console commands, and future queue jobs — it must be safe to call without an HTTP context.

### Consequences
**✅ Good:**
- A bug in one layer doesn't expose the other.
- The service can be called from artisan commands, jobs, and tests without accidentally bypassing auth.
- Policy provides clean `authorize()` calls in the controller; Service provides programmatic safety.

**⚠️ Trade-off:**
- Role logic appears in two places (Policy + Service). Mitigated by both delegating to `UserRole::allowedTransitions()` — a single source of truth.

---

## ADR-005: Soft Deletes on Documents, Hard Deletes Forbidden

**Status:** Accepted

### Decision
Documents use `SoftDeletes`. Hard deletion (`forceDelete`) is not exposed via any API route.

### Consequences
**✅ Good:**
- Published documents are never truly lost — critical for compliance and audit.
- Deleted drafts are recoverable by an admin.
- The transition ledger (`document_transitions`) references `document_id` with `cascadeOnDelete` — this only triggers on hard delete, which never happens via the API.

---

## ADR-006: `workflow_config` and `metadata` JSON Columns — Extension Points

**Status:** Accepted

### Decision
Two nullable JSON columns on `documents`:
- `workflow_config`: Per-document workflow overrides (e.g., "this document requires two approvers").
- `metadata`: Consumer-defined fields (department, priority, tags, external references).

### Consequences
**✅ Good:**
- The core schema stays lean and stable.
- Teams can store domain-specific data without schema migrations.
- Future v2 features (multi-step configurable chains, SLA deadlines) can be prototyped in `workflow_config` before being promoted to real columns.

**⚠️ Trade-off:**
- JSON columns are not indexed; don't query them in `WHERE` clauses at scale. If a `metadata` field becomes a frequent filter, extract it to a proper column.

---

## ADR-007: `created_at` Only on Transition and Audit Tables (No `updated_at`)

**Status:** Accepted

### Decision
`document_transitions` and `audit_logs` have only a `created_at` timestamp, no `updated_at`. The Eloquent model boots with a `creating` observer to set it automatically.

### Consequences
**✅ Good:**
- Structurally enforces immutability: Eloquent `save()` won't add an `updated_at` column that doesn't exist.
- Communicates intent to future developers: these rows are ledger entries, not mutable records.
- Slightly smaller rows (one fewer timestamp per record at potentially high volume).

---

## What I'd Do Differently in Production

1. **Multi-step configurable chains** — Store the step graph in `workflow_config` JSON and drive transitions dynamically, rather than hardcoding the graph in the enum. This allows per-document-type workflows without code changes.

2. **Parallel approval** — The current model is linear. Real ERP workflows often require "all three department heads must approve." This needs a `document_approvers` pivot table and consensus logic in the service.

3. **SLA / deadline tracking** — Add a `due_at` timestamp to `document_transitions`. A scheduled job fires overdue notifications.

4. **Optimistic locking** — Add a `version` integer column and check it on transition to prevent lost updates from concurrent requests.

5. **Event sourcing** — For compliance-heavy use cases (financial documents, regulated industries), derive document state entirely from the `document_transitions` ledger rather than storing `status` on the document itself.
