<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\User;

/**
 * DocumentPolicy
 *
 * Laravel's policy layer sits between the HTTP controller and the service.
 * It answers the question "is this user allowed to attempt this action?"
 * before any business logic runs.
 *
 * Controllers call:  $this->authorize('transition', [$document, $toStatus]);
 * The WorkflowService has an additional role-check as defence-in-depth.
 */
class DocumentPolicy
{
    /**
     * Admins bypass all policy checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can list documents.
    }

    public function view(User $user, Document $document): bool
    {
        return true; // All authenticated users can view any document.
    }

    public function create(User $user): bool
    {
        // Authors create documents; others can too (reviewers draft SOPs, etc.)
        return in_array($user->role, [
            UserRole::AUTHOR,
            UserRole::ADMIN,
        ]);
    }

    public function update(User $user, Document $document): bool
    {
        // Only the author can edit, and only while in draft or rejected state.
        return $user->id === $document->author_id
            && in_array($document->status, [
                DocumentStatus::DRAFT,
                DocumentStatus::REJECTED,
            ]);
    }

    public function delete(User $user, Document $document): bool
    {
        // Authors can soft-delete their own drafts; not published docs.
        return $user->id === $document->author_id
            && $document->status === DocumentStatus::DRAFT;
    }

    // ── Workflow transitions ──────────────────────────────────────────────────

    /**
     * Generic transition gate — called by the controller as:
     *   $this->authorize('transition', [$document, $toStatus])
     *
     * @param  DocumentStatus  $toStatus  The state the user wants to move to.
     */
    public function transition(User $user, Document $document, DocumentStatus $toStatus): bool
    {
        // The state machine must allow the edge first.
        if (!$document->canTransitionTo($toStatus)) {
            return false;
        }

        // The user's role must cover the target state.
        if (!$user->canPerformTransition($toStatus)) {
            return false;
        }

        // Extra guard: only the author submits their own document.
        if ($toStatus === DocumentStatus::PENDING && $user->id !== $document->author_id) {
            return false;
        }

        return true;
    }

    /**
     * Shorthand used by UI layers to check if the submit button should show.
     */
    public function submit(User $user, Document $document): bool
    {
        return $this->transition($user, $document, DocumentStatus::PENDING);
    }

    public function review(User $user, Document $document): bool
    {
        return $this->transition($user, $document, DocumentStatus::IN_REVIEW);
    }

    public function approve(User $user, Document $document): bool
    {
        return $this->transition($user, $document, DocumentStatus::APPROVED);
    }

    public function reject(User $user, Document $document): bool
    {
        return $user->hasRole(UserRole::REVIEWER) || $user->hasRole(UserRole::APPROVER);
    }

    public function publish(User $user, Document $document): bool
    {
        return $this->transition($user, $document, DocumentStatus::PUBLISHED);
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function viewAuditLog(User $user, Document $document): bool
    {
        // Author can see their own document's log; reviewers/approvers can always see it.
        return $user->id === $document->author_id
            || in_array($user->role, [
                UserRole::REVIEWER,
                UserRole::APPROVER,
                UserRole::PUBLISHER,
            ]);
    }
}
