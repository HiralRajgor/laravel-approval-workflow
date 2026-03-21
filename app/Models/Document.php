<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int            $id
 * @property string         $title
 * @property string|null    $body
 * @property DocumentStatus $status
 * @property int            $author_id
 * @property int|null       $current_step
 * @property array|null     $workflow_config
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class Document extends Model
{
    use HasFactory, SoftDeletes, HasAuditLog;

    protected $fillable = [
        'title',
        'body',
        'status',
        'author_id',
        'current_step',
        'workflow_config',
        'metadata',
    ];

    protected $casts = [
        'status'          => DocumentStatus::class,
        'workflow_config' => 'array',
        'metadata'        => 'array',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(DocumentTransition::class)->latest();
    }

    public function latestTransition(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DocumentTransition::class)->latestOfMany();
    }

    // -------------------------------------------------------------------------
    // State helpers
    // -------------------------------------------------------------------------

    /**
     * Fluent check used in policies and services.
     */
    public function canTransitionTo(DocumentStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function isPublished(): bool
    {
        return $this->status === DocumentStatus::PUBLISHED;
    }

    public function isRejected(): bool
    {
        return $this->status === DocumentStatus::REJECTED;
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForStatus($query, DocumentStatus $status)
    {
        return $query->where('status', $status->value);
    }

    public function scopePendingAction($query)
    {
        return $query->whereIn('status', [
            DocumentStatus::PENDING->value,
            DocumentStatus::IN_REVIEW->value,
        ]);
    }
}
