<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Events\DocumentTransitioned;
use App\Mail\DocumentApprovedMail;
use App\Mail\DocumentRejectedMail;
use App\Mail\DocumentSubmittedMail;
use App\Models\Document;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = app(WorkflowService::class);

        // Fix 1: ensure Blade cache dir exists so mail templates compile in tests
        $viewCachePath = storage_path('framework/views');
        if (!is_dir($viewCachePath)) {
            mkdir($viewCachePath, 0755, true);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function author(): User
    {
        return User::factory()->author()->create();
    }

    private function reviewer(): User
    {
        return User::factory()->reviewer()->create();
    }

    private function approver(): User
    {
        return User::factory()->approver()->create();
    }

    private function publisher(): User
    {
        return User::factory()->publisher()->create();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function draft(): Document
    {
        return Document::factory()->draft()->create(['author_id' => $this->author()->id]);
    }

    // ── State machine guard tests ─────────────────────────────────────────────

    #[Test]
    public function it_rejects_an_invalid_state_skip(): void
    {
        $doc = $this->draft();
        $this->expectException(ValidationException::class);
        $this->workflow->transition($doc, DocumentStatus::APPROVED, $this->admin());
    }

    #[Test]
    public function it_rejects_a_backwards_transition(): void
    {
        $doc = Document::factory()->inReview()->create(['author_id' => $this->author()->id]);
        $this->expectException(ValidationException::class);
        $this->workflow->transition($doc, DocumentStatus::DRAFT, $this->admin());
    }

    #[Test]
    public function published_document_cannot_be_transitioned(): void
    {
        $doc = Document::factory()->published()->create(['author_id' => $this->author()->id]);
        $this->expectException(ValidationException::class);
        $this->workflow->transition($doc, DocumentStatus::DRAFT, $this->admin());
    }

    // ── RBAC guard tests ──────────────────────────────────────────────────────

    #[Test]
    public function reviewer_cannot_publish_a_document(): void
    {
        $doc = Document::factory()->approved()->create(['author_id' => $this->author()->id]);
        $this->expectException(AuthorizationException::class);
        $this->workflow->transition($doc, DocumentStatus::PUBLISHED, $this->reviewer());
    }

    #[Test]
    public function publisher_cannot_approve_a_document(): void
    {
        $doc = Document::factory()->inReview()->create(['author_id' => $this->author()->id]);
        $this->expectException(AuthorizationException::class);
        $this->workflow->transition($doc, DocumentStatus::APPROVED, $this->publisher());
    }

    #[Test]
    public function admin_can_perform_any_valid_transition(): void
    {
        $doc = $this->draft();
        $result = $this->workflow->transition($doc, DocumentStatus::PENDING, $this->admin());
        $this->assertEquals(DocumentStatus::PENDING, $result->status);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    #[Test]
    public function full_lifecycle_draft_to_published(): void
    {
        Mail::fake();

        $author = $this->author();
        $reviewer = $this->reviewer();
        $approver = $this->approver();
        $publisher = $this->publisher();

        $doc = Document::factory()->draft()->create(['author_id' => $author->id]);

        $doc = $this->workflow->submit($doc, $author);
        $this->assertEquals(DocumentStatus::PENDING, $doc->status);

        $doc = $this->workflow->startReview($doc, $reviewer);
        $this->assertEquals(DocumentStatus::IN_REVIEW, $doc->status);

        $doc = $this->workflow->approve($doc, $approver, 'Looks great!');
        $this->assertEquals(DocumentStatus::APPROVED, $doc->status);

        $doc = $this->workflow->publish($doc, $publisher);
        $this->assertEquals(DocumentStatus::PUBLISHED, $doc->status);

        $this->assertCount(4, $doc->transitions);
    }

    // ── Rejection & revision cycle ────────────────────────────────────────────

    #[Test]
    public function rejection_cycle_sends_document_back_to_draft(): void
    {
        Mail::fake();

        $author = $this->author();
        $reviewer = $this->reviewer();

        $doc = Document::factory()->inReview()->create(['author_id' => $author->id]);

        $doc = $this->workflow->reject($doc, $reviewer, 'Please add more detail.');
        $this->assertEquals(DocumentStatus::REJECTED, $doc->status);

        $doc = $this->workflow->revertToDraft($doc, $author);
        $this->assertEquals(DocumentStatus::DRAFT, $doc->status);
    }

    // ── Event & mail assertions ───────────────────────────────────────────────

    #[Test]
    public function it_fires_the_document_transitioned_event(): void
    {
        Event::fake([DocumentTransitioned::class]);

        $doc = $this->draft();
        $this->workflow->submit($doc, $doc->author);

        Event::assertDispatched(DocumentTransitioned::class, function ($e) use ($doc) {
            return $e->document->id === $doc->id
                && $e->fromStatus === DocumentStatus::DRAFT
                && $e->toStatus === DocumentStatus::PENDING;
        });
    }

    #[Test]
    public function submission_queues_mail_to_reviewers(): void
    {
        Mail::fake();

        $reviewer = $this->reviewer();
        $doc = $this->draft();

        $this->workflow->submit($doc, $doc->author);

        Mail::assertQueued(DocumentSubmittedMail::class, function ($mail) use ($reviewer) {
            return $mail->hasTo($reviewer->email);
        });
    }

    #[Test]
    public function approval_queues_mail_to_author(): void
    {
        Mail::fake();

        $author = $this->author();
        $approver = $this->approver();
        $doc = Document::factory()->inReview()->create(['author_id' => $author->id]);

        $this->workflow->approve($doc, $approver);

        Mail::assertQueued(DocumentApprovedMail::class, function ($mail) use ($author) {
            return $mail->hasTo($author->email);
        });
    }

    #[Test]
    public function rejection_queues_mail_to_author_with_comment(): void
    {
        Mail::fake();

        $author = $this->author();
        $reviewer = $this->reviewer();
        $doc = Document::factory()->inReview()->create(['author_id' => $author->id]);

        $this->workflow->reject($doc, $reviewer, 'Needs revision.');

        Mail::assertQueued(DocumentRejectedMail::class, function ($mail) use ($author) {
            return $mail->hasTo($author->email);
        });
    }

    // ── Ledger & audit log ────────────────────────────────────────────────────

    #[Test]
    public function transition_is_recorded_in_ledger_table(): void
    {
        Mail::fake();

        $doc = $this->draft();
        $this->workflow->submit($doc, $doc->author, 'Ready for review.');

        $this->assertDatabaseHas('document_transitions', [
            'document_id' => $doc->id,
            'from_status' => 'draft',
            'to_status' => 'pending',
            'comment' => 'Ready for review.',
        ]);
    }

    #[Test]
    public function audit_log_entry_is_created_on_transition(): void
    {
        Mail::fake();

        $doc = $this->draft();
        $this->workflow->submit($doc, $doc->author);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Document::class,
            'auditable_id' => $doc->id,
            'event' => 'status_changed',
        ]);
    }

    // ── HTTP layer ────────────────────────────────────────────────────────────

    #[Test]
    public function api_transition_endpoint_returns_403_for_wrong_role(): void
    {
        $author = $this->author();
        $doc = Document::factory()->inReview()->create(['author_id' => $author->id]);

        // Fix 2: pass 'sanctum' guard explicitly so actingAs works on API routes
        $this->actingAs($author, 'sanctum')
            ->postJson("/api/documents/{$doc->id}/transition", ['status' => 'approved'])
            ->assertForbidden();
    }

    #[Test]
    public function api_transition_endpoint_returns_422_for_invalid_state(): void
    {
        $admin = $this->admin();
        $doc = Document::factory()->published()->create(['author_id' => $this->author()->id]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/documents/{$doc->id}/transition", ['status' => 'draft'])
            ->assertUnprocessable();
    }

    #[Test]
    public function api_returns_allowed_transitions_in_document_resource(): void
    {
        $doc = $this->draft();

        $this->actingAs($doc->author, 'sanctum')
            ->getJson("/api/documents/{$doc->id}")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.allowed_transitions.0.value', 'pending');
    }
}
