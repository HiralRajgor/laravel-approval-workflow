<?php

namespace App\Events;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a successful document status transition.
 *
 * Listeners receive this event and handle side-effects:
 *   - Send email notifications
 *   - Post Slack messages
 *   - Trigger webhooks
 *   - Update search indexes
 *
 * The event carries the complete context so listeners never
 * need to issue additional queries just to know what happened.
 */
class DocumentTransitioned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Document       $document,
        public readonly DocumentStatus $fromStatus,
        public readonly DocumentStatus $toStatus,
        public readonly User           $actor,
        public readonly ?string        $comment = null,
    ) {}

    /**
     * True when the document has just been submitted for the first time.
     */
    public function isSubmission(): bool
    {
        return $this->fromStatus === DocumentStatus::DRAFT
            && $this->toStatus  === DocumentStatus::PENDING;
    }

    /**
     * True when the document has been approved (and now awaits publishing).
     */
    public function isApproval(): bool
    {
        return $this->toStatus === DocumentStatus::APPROVED;
    }

    /**
     * True when the document was rejected.
     */
    public function isRejection(): bool
    {
        return $this->toStatus === DocumentStatus::REJECTED;
    }

    /**
     * True when the document has been published.
     */
    public function isPublished(): bool
    {
        return $this->toStatus === DocumentStatus::PUBLISHED;
    }
}
