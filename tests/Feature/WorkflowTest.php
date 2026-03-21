<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use App\Events\DocumentTransitioned;
use App\Mail\DocumentApprovedMail;
use App\Mail\DocumentRejectedMail;
use App\Mail\DocumentSubmittedMail;
use App\Models\Document;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * WorkflowTest
 *
 * Tests cover:
 *   ✓ Valid state transitions for each role
 *   ✓ Invalid / skipped transitions are rejected (state machine guard)
 *   ✓ Role mismatch is rejected (RBAC guard)
 *   ✓ Domain event is fired on every successful transition
 *   ✓ Mail is queued (not sent sync) on the correct transitions
 *   ✓ Transition record is written to the ledger table
 *   ✓ Audit log entry is created
 *   ✓ Published document cannot be transitioned further (terminal state)
 */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = app(WorkflowService::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function author(): User    { return User::factory()->author()->create(); }
    private function reviewer(): User  { return User::factory()->reviewer()->create(); }
    private function approver(): User  { return User::factory()->approver()->create(); }
    private function publisher(): User { return User::factory()->publisher()->create(); }
    private function admin(): User     { return User::factory()->admin()->create(); }

    private function draft(): Document
    {
        return Document::factory()->draft()->create(['author_id' => $this->author()->id]);
    }

    // ── State machine guard tests ─────────────────────────────────────────────

    /** @test */
    public function it_rejects_an_invalid_state_skip(): void
    {
        // Cannot jump draft → approved (must pass through pending, in_review)
        $doc = $this->draft();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->workflow->transition($doc, DocumentStatus::APPROVED, $this->admin());
    }

    /** @test */
    public function it_rejects_a_backwards_transition(): void
    {
        $doc = Document::factory()->inReview()->create(['author_id' => $this->author()->id]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->workflow->transition($doc, DocumentStatus::DRAFT, $this->admin());
    }

    /** @test */
    public function published_document_cannot_be_transitioned(): void
    {
        $doc = Document::factory()->published()->create(['author_id' => $this->author()->id]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->workflow->transition($doc, DocumentStatus::DRAFT, $this->admin());
    }

    // ── RBAC guard tests ─────────────────────────────────────────────────────

    /** @test */
    public function reviewer_cannot_publish_a_document(): void
    {
        $doc = Document::factory()->approved()->create(['author_id' => $this->author()->id]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->workflow->transition($doc, DocumentStatus::PUBLISHED, $this->reviewer());
    }

    /** @test */
    public function publisher_cannot_approve_a_document(): void
    {
        $doc = Document::factory()->inReview()->create(['author_id' => $this->author()->id]);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->workflow->transition($doc, DocumentStatus::APPROVED, $this->publisher());
    }

    /** @test */
    public function admin_can_perform_any_valid_transition(): void
    {
        $doc   = $this->draft();
        $admin = $this->admin();

        $result = $this->workflow->transition($doc, DocumentStatus::PENDING, $admin);

        $this->assertEquals(DocumentStatus::PENDING, $result->status);
    }

    // ── Happy path — full lifecycle ───────────────────────────────────────────

    /** @test */
    public function full_lifecycle_draft_to_published(): void
    {
        $author    = $this->author();
        $reviewer  = $this->reviewer();
        $approver  = $this->approver();
        $publisher = $this->publisher();

        $doc = Document::factory()->draft()->create(['author_id' => $author->id]);

        // 1. Author submits
        $doc = $this->workflow->submit($doc, $author);
        $this->assertEquals(DocumentStatus::PENDING, $doc->status);

        // 2. Reviewer picks it up
        $doc = $this->workflow->startReview($doc, $reviewer);
        $this->assertEquals(DocumentStatus::IN_REVIEW, $doc->status);

        // 3. Approver approves
        $doc = $this->workflow->approve($doc, $approver, 'Looks great!');
        $this->assertEquals(DocumentStatus::APPROVED, $doc->status);

        // 4. Publisher publishes
        $doc = $this->workflow->publish($doc, $publisher);
        $this->assertEquals(DocumentStatus::PUBLISHED, $doc->status);

        // Verify 4 transition records exist
        $this->assertCount(4, $doc->transitions);
    }

    // ── Rejection & revision cycle ────────────────────────────────────────────

    /** @test */
    public function rejection_cycle_sends_document_back_to_draft(): void
    {
        $author   = $this->author();
        $reviewer = $this->reviewer();

        $doc = Document::factory()->inReview()->create(['author_id' => $author->id]);

        $doc = $this->workflow->reject($doc, $reviewer, 'Please add more detail.');
        $this->assertEquals(DocumentStatus::REJECTED, $doc->status);

        // Author reverts to draft for revision
        $doc = $this->workflow->revertToDraft($doc, $author);
        $this->assertEquals(DocumentStatus::DRAFT, $doc->status);
    }

    // ── Event & mail assertions ───────────────────────────────────────────────

    /** @test */
    public function it_fires_the_document_transitioned_event(): void
    {
        Event::fake([DocumentTransitioned::class]);

        $doc = $this->draft();
        $this->workflow->submit($doc, $doc->author);

        Event::assertDispatched(DocumentTransitioned::class, function ($e) use ($doc) {
            return $e->document->id  === $doc->id
                && $e->fromStatus    === DocumentStatus::DRAFT
                && $e->toStatus      === DocumentStatus::PENDING;
        });
    }

    /** @test */
    public function submission_queues_mail_to_reviewers(): void
    {
        Mail::fake();

        $reviewer = $this->reviewer();
        $doc      = $this->draft();

        $this->workflow->submit($doc, $doc->author);

        Mail::assertQueued(DocumentSubmittedMail::class, function ($mail) use ($reviewer) {
            return $mail->hasTo($reviewer->email);
        });
    }

    /** @test */
    public function approval_queues_mail_to_author(): void
    {
        Mail::fake();

        $author   = $this->author();
        $approver = $this->approver();
        $doc      = Document::factory()->inReview()->create(['author_id' => $author->id]);

        $this->workflow->approve($doc, $approver);

        Mail::assertQueued(DocumentApprovedMail::class, function ($mail) use ($author) {
            return $mail->hasTo($author->email);
        });
    }

    /** @test */
    public function rejection_queues_mail_to_author_with_comment(): void
    {
        Mail::fake();

        $author   = $this->author();
        $reviewer = $this->reviewer();
        $doc      = Document::factory()->inReview()->create(['author_id' => $author->id]);

        $this->workflow->reject($doc, $reviewer, 'Needs revision.');

        Mail::assertQueued(DocumentRejectedMail::class, function ($mail) use ($author) {
            return $mail->hasTo($author->email);
        });
    }

    // ── Ledger & audit log ────────────────────────────────────────────────────

    /** @test */
    public function transition_is_recorded_in_ledger_table(): void
    {
        $doc = $this->draft();

        $this->workflow->submit($doc, $doc->author, 'Ready for review.');

        $this->assertDatabaseHas('document_transitions', [
            'document_id' => $doc->id,
            'from_status' => 'draft',
            'to_status'   => 'pending',
            'comment'     => 'Ready for review.',
        ]);
    }

    /** @test */
    public function audit_log_entry_is_created_on_transition(): void
    {
        $doc = $this->draft();

        $this->workflow->submit($doc, $doc->author);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Document::class,
            'auditable_id'   => $doc->id,
            'event'          => 'status_changed',
        ]);
    }

    // ── HTTP layer ────────────────────────────────────────────────────────────

    /** @test */
    public function api_transition_endpoint_returns_403_for_wrong_role(): void
    {
        $author = $this->author();
        $doc    = Document::factory()->inReview()->create(['author_id' => $author->id]);

        // Author tries to approve — not their role
        $this->actingAs($author)
             ->postJson("/api/documents/{$doc->id}/transition", ['status' => 'approved'])
             ->assertForbidden();
    }

    /** @test */
    public function api_transition_endpoint_returns_422_for_invalid_state(): void
    {
        $admin = $this->admin();
        $doc   = Document::factory()->published()->create(['author_id' => $this->author()->id]);

        $this->actingAs($admin)
             ->postJson("/api/documents/{$doc->id}/transition", ['status' => 'draft'])
             ->assertUnprocessable();
    }

    /** @test */
    public function api_returns_allowed_transitions_in_document_resource(): void
    {
        $doc = $this->draft();

        $this->actingAs($doc->author)
             ->getJson("/api/documents/{$doc->id}")
             ->assertOk()
             ->assertJsonPath('data.status.value', 'draft')
             ->assertJsonPath('data.allowed_transitions.0.value', 'pending');
    }
}
