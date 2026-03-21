<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Generic polymorphic audit log.
 * Attached to any model via the HasAuditLog trait.
 */
class AuditLog extends Model
{
    public $timestamps  = false;
    const CREATED_AT    = 'created_at';

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'user_id',
        'event',
        'meta',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->created_at = now());
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
