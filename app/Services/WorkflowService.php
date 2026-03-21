<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Events\DocumentTransitioned;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WorkflowService
 *
 * Single entry-point for all document state transitions.
 *
 * Design decisions:
 * ─────────────────
 * 1. ALL transition logic lives here — controllers stay thin.
 * 2. DB transaction wraps the status change + transition record + audit event
 *    so the database never has an inconsistent view.
 * 3. The actual side-effects (mail, Slack, webhooks) are decoupled via the
 *    DocumentTransitioned event — this service doesn't know or care about them.
 * 4. Authorization is checked BEFORE the DB transaction; we fail fast on
 *    policy violations without touching the database at all.
 */
class WorkflowService
{
    /**
     * Transition a document to the given status.
     *
     * @throws \Illuminate\Validation\ValidationException  On invalid transition.
     * @throws \Illuminate\Auth\Access\AuthorizationException  On policy failure.
     */
    public function transition(
        Document $document,
        DocumentStatus $toStatus,
        User $actor,
        ?string $comment = null
    ): Document {
        // ── 1. State-machine guard ─────────────────────────────────────────
        if (! $document->canTransitionTo($toStatus)) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'Cannot transition from [%s] to [%s]. Allowed next states: [%s].',
                    $document->status->label(),
                    $toStatus->label(),
                    implode(', ', array_map(
                        fn ($s) => $s->label(),
                        $document->status->allowedTransitions()
                    )) ?: 'none'
                ),
            ]);
        }

        // ── 2. Role / permission guard ─────────────────────────────────────
        if (! $actor->isAdmin() && ! $actor->canPerformTransition($toStatus)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                "Your role [{$actor->role->label()}] is not permitted to move a document to [{$toStatus->label()}]."
            );
        }

        // ── 3. Atomic write ────────────────────────────────────────────────
        $fromStatus = $document->status;

        DB::transaction(function () use ($document, $fromStatus, $toStatus, $actor, $comment) {
            // a) Update the document's status column
            $document->update(['status' => $toStatus]);

            // b) Append an immutable transition record (the ledger)
            $transition = $document->transitions()->create([
                'actor_id'    => $actor->id,
                'from_status' => $fromStatus,
                'to_status'   => $toStatus,
                'comment'     => $comment,
                'ip_address'  => request()?->ip(),
            ]);

            // c) Append to the generic audit log (different purpose: who touched what)
            $document->logAuditEvent('status_changed', [
                'from'    => $fromStatus->value,
                'to'      => $toStatus->value,
                'comment' => $comment,
            ], $actor->id);
        });

        // ── 4. Fire domain event (outside the transaction on purpose) ───────
        // We don't want a mail-send failure to roll back the status change.
        // If notification delivery fails, the transition record already exists
        // and can be replayed via a listener retry.
        event(new DocumentTransitioned($document->fresh(), $fromStatus, $toStatus, $actor, $comment));

        return $document->fresh(['author', 'transitions', 'latestTransition']);
    }

    /**
     * Submit a draft for review (convenience wrapper).
     */
    public function submit(Document $document, User $actor, ?string $comment = null): Document
    {
        return $this->transition($document, DocumentStatus::PENDING, $actor, $comment);
    }

    /**
     * Start reviewing a pending document.
     */
    public function startReview(Document $document, User $actor, ?string $comment = null): Document
    {
        return $this->transition($document, DocumentStatus::IN_REVIEW, $actor, $comment);
    }

    /**
     * Approve a document under review.
     */
    public function approve(Document $document, User $actor, ?string $comment = null): Document
    {
        return $this->transition($document, DocumentStatus::APPROVED, $actor, $comment);
    }

    /**
     * Reject a document (from pending or in_review).
     */
    public function reject(Document $document, User $actor, ?string $comment = null): Document
    {
        return $this->transition($document, DocumentStatus::REJECTED, $actor, $comment);
    }

    /**
     * Publish an approved document.
     */
    public function publish(Document $document, User $actor, ?string $comment = null): Document
    {
        return $this->transition($document, DocumentStatus::PUBLISHED, $actor, $comment);
    }

    /**
     * Return a rejected document to draft so the author can revise it.
     */
    public function revertToDraft(Document $document, User $actor, ?string $comment = null): Document
    {
        return $this->transition($document, DocumentStatus::DRAFT, $actor, $comment);
    }
}
