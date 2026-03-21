<?php

namespace Tests\Unit;

use App\Enums\DocumentStatus;
use PHPUnit\Framework\TestCase;

/**
 * DocumentStatusTest
 *
 * Pure unit tests — no database, no framework boot.
 * These run in milliseconds and guard the state machine graph.
 */
class DocumentStatusTest extends TestCase
{
    /** @test */
    public function draft_can_only_transition_to_pending(): void
    {
        $allowed = DocumentStatus::DRAFT->allowedTransitions();

        $this->assertCount(1, $allowed);
        $this->assertContains(DocumentStatus::PENDING, $allowed);
    }

    /** @test */
    public function pending_can_transition_to_in_review_or_rejected(): void
    {
        $allowed = DocumentStatus::PENDING->allowedTransitions();

        $this->assertContains(DocumentStatus::IN_REVIEW, $allowed);
        $this->assertContains(DocumentStatus::REJECTED, $allowed);
        $this->assertNotContains(DocumentStatus::APPROVED, $allowed);
    }

    /** @test */
    public function in_review_can_transition_to_approved_or_rejected(): void
    {
        $allowed = DocumentStatus::IN_REVIEW->allowedTransitions();

        $this->assertContains(DocumentStatus::APPROVED, $allowed);
        $this->assertContains(DocumentStatus::REJECTED, $allowed);
        $this->assertNotContains(DocumentStatus::PUBLISHED, $allowed);
    }

    /** @test */
    public function approved_can_only_transition_to_published(): void
    {
        $allowed = DocumentStatus::APPROVED->allowedTransitions();

        $this->assertCount(1, $allowed);
        $this->assertContains(DocumentStatus::PUBLISHED, $allowed);
    }

    /** @test */
    public function published_is_a_terminal_state(): void
    {
        $this->assertTrue(DocumentStatus::PUBLISHED->isTerminal());
        $this->assertEmpty(DocumentStatus::PUBLISHED->allowedTransitions());
    }

    /** @test */
    public function rejected_can_return_to_draft_for_revision(): void
    {
        $this->assertTrue(
            DocumentStatus::REJECTED->canTransitionTo(DocumentStatus::DRAFT)
        );
    }

    /** @test */
    public function can_transition_to_returns_false_for_invalid_edges(): void
    {
        // Spot-check a set of invalid edges
        $invalidEdges = [
            [DocumentStatus::DRAFT,     DocumentStatus::APPROVED],
            [DocumentStatus::DRAFT,     DocumentStatus::PUBLISHED],
            [DocumentStatus::PENDING,   DocumentStatus::DRAFT],
            [DocumentStatus::PENDING,   DocumentStatus::PUBLISHED],
            [DocumentStatus::APPROVED,  DocumentStatus::DRAFT],
            [DocumentStatus::PUBLISHED, DocumentStatus::DRAFT],
            [DocumentStatus::PUBLISHED, DocumentStatus::APPROVED],
        ];

        foreach ($invalidEdges as [$from, $to]) {
            $this->assertFalse(
                $from->canTransitionTo($to),
                "Expected [{$from->label()}] → [{$to->label()}] to be INVALID but it was allowed."
            );
        }
    }

    /** @test */
    public function all_statuses_have_labels_and_colors(): void
    {
        foreach (DocumentStatus::cases() as $status) {
            $this->assertNotEmpty($status->label(), "Missing label for {$status->value}");
            $this->assertNotEmpty($status->color(), "Missing color for {$status->value}");
        }
    }
}
