<?php

namespace App\Listeners;

use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use App\Events\DocumentTransitioned;
use App\Mail\DocumentApprovedMail;
use App\Mail\DocumentPublishedMail;
use App\Mail\DocumentRejectedMail;
use App\Mail\DocumentSubmittedMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SendTransitionNotification
 *
 * Listens to DocumentTransitioned and dispatches the appropriate mailable.
 *
 * Implements ShouldQueue so mail is sent asynchronously via the queue worker —
 * the HTTP response is not held up by SMTP latency.
 *
 * Why a single listener instead of one per transition?
 * ─────────────────────────────────────────────────────
 * A single listener keeps the EventServiceProvider mapping simple and
 * eliminates conditional duplication. The routing logic (who gets what mail)
 * is in one readable place. If you need fine-grained queue configuration per
 * mail type, split into dedicated listeners at that point.
 */
class SendTransitionNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     * Failed mail jobs will be retried up to 3 times before going to failed_jobs.
     */
    public int $tries = 3;

    /**
     * Delay between retries (seconds): 60 → 300 → 900.
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(DocumentTransitioned $event): void
    {
        match (true) {
            $event->isSubmission() => $this->notifyOnSubmission($event),
            $event->isApproval() => $this->notifyOnApproval($event),
            $event->isRejection() => $this->notifyOnRejection($event),
            $event->isPublished() => $this->notifyOnPublished($event),
            default => $this->notifyReviewStarted($event),
        };
    }

    // ── Submission: notify all reviewers ────────────────────────────────────
    private function notifyOnSubmission(DocumentTransitioned $event): void
    {
        $reviewers = User::where('role', UserRole::REVIEWER->value)->get();

        foreach ($reviewers as $reviewer) {
            Mail::to($reviewer)->queue(
                new DocumentSubmittedMail($event->document, $event->actor),
            );
        }
    }

    // ── Approval: notify the author and all publishers ───────────────────────
    private function notifyOnApproval(DocumentTransitioned $event): void
    {
        Mail::to($event->document->author)->queue(
            new DocumentApprovedMail($event->document, $event->actor),
        );

        $publishers = User::where('role', UserRole::PUBLISHER->value)->get();
        foreach ($publishers as $publisher) {
            Mail::to($publisher)->queue(
                new DocumentApprovedMail($event->document, $event->actor),
            );
        }
    }

    // ── Rejection: notify the author with the reviewer's comment ─────────────
    private function notifyOnRejection(DocumentTransitioned $event): void
    {
        Mail::to($event->document->author)->queue(
            new DocumentRejectedMail($event->document, $event->actor, $event->comment),
        );
    }

    // ── Published: notify the author ─────────────────────────────────────────
    private function notifyOnPublished(DocumentTransitioned $event): void
    {
        Mail::to($event->document->author)->queue(
            new DocumentPublishedMail($event->document, $event->actor),
        );
    }

    // ── Review started: notify the author ────────────────────────────────────
    private function notifyReviewStarted(DocumentTransitioned $event): void
    {
        if ($event->toStatus === DocumentStatus::IN_REVIEW) {
            Mail::to($event->document->author)->queue(
                new DocumentSubmittedMail($event->document, $event->actor),
            );
        }
    }

    /**
     * Handle a job failure — log it; the failed_jobs table captures the payload.
     */
    public function failed(DocumentTransitioned $event, \Throwable $exception): void
    {
        Log::error('Notification dispatch failed', [
            'document_id' => $event->document->id,
            'transition' => "{$event->fromStatus->value} → {$event->toStatus->value}",
            'error' => $exception->getMessage(),
        ]);
    }
}
