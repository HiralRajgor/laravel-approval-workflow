<?php

namespace App\Enums;

enum UserRole: string
{
    case AUTHOR = 'author';
    case REVIEWER = 'reviewer';
    case APPROVER = 'approver';
    case PUBLISHER = 'publisher';
    case ADMIN = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::AUTHOR => 'Author',
            self::REVIEWER => 'Reviewer',
            self::APPROVER => 'Approver',
            self::PUBLISHER => 'Publisher',
            self::ADMIN => 'Administrator',
        };
    }

    /**
     * Which transitions each role is permitted to trigger.
     *
     * @return array<DocumentStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AUTHOR => [DocumentStatus::PENDING],
            self::REVIEWER => [DocumentStatus::IN_REVIEW, DocumentStatus::REJECTED],
            self::APPROVER => [DocumentStatus::APPROVED, DocumentStatus::REJECTED],
            self::PUBLISHER => [DocumentStatus::PUBLISHED],
            self::ADMIN => DocumentStatus::cases(), // omnipotent
        };
    }
}
