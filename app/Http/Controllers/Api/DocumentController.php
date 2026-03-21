<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\TransitionDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentTransitionResource;
use App\Models\Document;
use App\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @OA\Info(title="Document Approval Workflow API", version="1.0.0",
 *   description="Configurable multi-step document approval chain. Supports state-machine transitions, RBAC, audit log, and async mail notifications."
 * )
 *
 * @OA\SecurityScheme(securityScheme="sanctum", type="http", scheme="bearer")
 */
class DocumentController extends Controller
{
    public function __construct(private readonly WorkflowService $workflow) {}

    // ── List ─────────────────────────────────────────────────────────────────

    /**
     * @OA\Get(
     *   path="/api/documents",
     *   summary="List all documents",
     *   tags={"Documents"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="status", in="query", required=false,
     *
     *     @OA\Schema(type="string", enum={"draft","pending","in_review","approved","rejected","published"})
     *   ),
     *
     *   @OA\Response(response=200, description="Paginated document list")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);

        $query = Document::with(['author', 'latestTransition'])
            ->latest();

        if ($status = $request->query('status')) {
            $query->forStatus(DocumentStatus::from($status));
        }

        return DocumentResource::collection($query->paginate(20));
    }

    // ── Create ───────────────────────────────────────────────────────────────

    /**
     * @OA\Post(
     *   path="/api/documents",
     *   summary="Create a new draft document",
     *   tags={"Documents"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\RequestBody(required=true,
     *
     *     @OA\JsonContent(
     *       required={"title"},
     *
     *       @OA\Property(property="title", type="string", maxLength=255),
     *       @OA\Property(property="body",  type="string"),
     *       @OA\Property(property="metadata", type="object")
     *     )
     *   ),
     *
     *   @OA\Response(response=201, description="Document created"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $this->authorize('create', Document::class);

        $document = Document::create([
            ...$request->validated(),
            'author_id' => $request->user()->id,
            'status' => DocumentStatus::DRAFT,
        ]);

        $document->logAuditEvent('created', ['title' => $document->title]);

        return (new DocumentResource($document->load('author')))
            ->response()
            ->setStatusCode(201);
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /**
     * @OA\Get(
     *   path="/api/documents/{id}",
     *   summary="Get a single document",
     *   tags={"Documents"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *   @OA\Response(response=200, description="Document detail"),
     *   @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(Document $document): DocumentResource
    {
        $this->authorize('view', $document);

        return new DocumentResource(
            $document->load(['author', 'transitions.actor', 'latestTransition']),
        );
    }

    // ── Update ───────────────────────────────────────────────────────────────

    /**
     * @OA\Put(
     *   path="/api/documents/{id}",
     *   summary="Update a draft or rejected document",
     *   tags={"Documents"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *   @OA\RequestBody(required=true,
     *
     *     @OA\JsonContent(
     *
     *       @OA\Property(property="title", type="string"),
     *       @OA\Property(property="body",  type="string")
     *     )
     *   ),
     *
     *   @OA\Response(response=200, description="Document updated"),
     *   @OA\Response(response=403, description="Forbidden — not in editable state")
     * )
     */
    public function update(UpdateDocumentRequest $request, Document $document): DocumentResource
    {
        $this->authorize('update', $document);

        $document->update($request->validated());
        $document->logAuditEvent('updated', $request->validated());

        return new DocumentResource($document->fresh('author'));
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    /**
     * @OA\Delete(
     *   path="/api/documents/{id}",
     *   summary="Soft-delete a draft document",
     *   tags={"Documents"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *   @OA\Response(response=204, description="Deleted"),
     *   @OA\Response(response=403, description="Forbidden")
     * )
     */
    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $document->logAuditEvent('deleted');
        $document->delete();

        return response()->json(null, 204);
    }

    // ── Transition ───────────────────────────────────────────────────────────

    /**
     * @OA\Post(
     *   path="/api/documents/{id}/transition",
     *   summary="Transition a document to a new status",
     *   tags={"Workflow"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *   @OA\RequestBody(required=true,
     *
     *     @OA\JsonContent(
     *       required={"status"},
     *
     *       @OA\Property(property="status", type="string",
     *         enum={"pending","in_review","approved","rejected","published","draft"}
     *       ),
     *       @OA\Property(property="comment", type="string", description="Optional reviewer comment")
     *     )
     *   ),
     *
     *   @OA\Response(response=200, description="Transition successful"),
     *   @OA\Response(response=403, description="Forbidden — role or state mismatch"),
     *   @OA\Response(response=422, description="Invalid transition")
     * )
     */
    public function transition(TransitionDocumentRequest $request, Document $document): DocumentResource
    {
        $toStatus = DocumentStatus::from($request->validated('status'));

        $this->authorize('transition', [$document, $toStatus]);

        $updated = $this->workflow->transition(
            $document,
            $toStatus,
            $request->user(),
            $request->validated('comment'),
        );

        return new DocumentResource($updated);
    }

    // ── History ──────────────────────────────────────────────────────────────

    /**
     * @OA\Get(
     *   path="/api/documents/{id}/history",
     *   summary="Full transition history for a document",
     *   tags={"Workflow"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *   @OA\Response(response=200, description="Transition list")
     * )
     */
    public function history(Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        return DocumentTransitionResource::collection(
            $document->transitions()->with('actor')->get(),
        );
    }

    /**
     * @OA\Get(
     *   path="/api/documents/{id}/audit",
     *   summary="Audit log for a document",
     *   tags={"Workflow"},
     *   security={{"sanctum":{}}},
     *
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *
     *   @OA\Response(response=200, description="Audit entries")
     * )
     */
    public function auditLog(Document $document): AnonymousResourceCollection
    {
        $this->authorize('viewAuditLog', $document);

        return AuditLogResource::collection(
            $document->auditLogs()->with('user')->get(),
        );
    }
}
