<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable ledger row created every time a document changes state.
 * Never updated, never deleted — it is the audit spine of the workflow.
 *
 * @property int            $id
 * @property int            $document_id
 * @property int|null       $actor_id
 * @property DocumentStatus $from_status
 * @property DocumentStatus $to_status
 * @property string|null    $comment
 * @property string|null    $ip_address
 * @property \Carbon\Carbon $created_at
 */
class DocumentTransition extends Model
{
    // Transitions are written once and never mutated.
    public $timestamps = false;
    const CREATED_AT  = 'created_at';

    protected $fillable = [
        'document_id',
        'actor_id',
        'from_status',
        'to_status',
        'comment',
        'ip_address',
        'step_index',
    ];

    protected $casts = [
        'from_status' => DocumentStatus::class,
        'to_status'   => DocumentStatus::class,
        'created_at'  => 'datetime',
    ];

    // Force created_at to be set on insert even without updated_at
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at = now();
        });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function summary(): string
    {
        $actor = $this->actor?->name ?? 'System';
        $from  = $this->from_status->label();
        $to    = $this->to_status->label();

        return "{$actor} moved document from {$from} → {$to}";
    }
}
