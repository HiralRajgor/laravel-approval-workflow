<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case IN_REVIEW = 'in_review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PUBLISHED = 'published';

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending Review',
            self::IN_REVIEW => 'In Review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::PUBLISHED => 'Published',
        };
    }

    /**
     * Allowed transitions FROM this state.
     * The state machine enforces these — no bypass possible.
     *
     * @return array<DocumentStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::PENDING],
            self::PENDING => [self::IN_REVIEW, self::REJECTED],
            self::IN_REVIEW => [self::APPROVED, self::REJECTED],
            self::APPROVED => [self::PUBLISHED],
            self::REJECTED => [self::DRAFT],
            self::PUBLISHED => [],
        };
    }

    /**
     * Can this status transition to the given status?
     */
    public function canTransitionTo(DocumentStatus $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /**
     * Is this a terminal state (no further transitions)?
     */
    public function isTerminal(): bool
    {
        return empty($this->allowedTransitions());
    }

    /**
     * Returns the colour hint used by the API resource for frontends.
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::PENDING => 'yellow',
            self::IN_REVIEW => 'blue',
            self::APPROVED => 'green',
            self::REJECTED => 'red',
            self::PUBLISHED => 'emerald',
        };
    }
}
