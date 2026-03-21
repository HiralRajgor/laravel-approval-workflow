<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Trait HasAuditLog
 *
 * Mix into any Eloquent model to get a polymorphic audit trail.
 * Usage:  use HasAuditLog;
 *
 * The trait also exposes a static helper so services can log
 * arbitrary events without going through an Eloquent model.
 */
trait HasAuditLog
{
    /**
     * Polymorphic relation — one model can have many log entries.
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')
                    ->latest();
    }

    /**
     * Append a structured entry to this model's audit trail.
     *
     * @param  string               $event   Short, machine-readable verb: 'submitted', 'approved', …
     * @param  array<string, mixed> $meta    Arbitrary context (old_status, new_status, comment, …)
     * @param  int|null             $userId  Defaults to the currently authenticated user.
     */
    public function logAuditEvent(
        string $event,
        array  $meta   = [],
        ?int   $userId = null
    ): AuditLog {
        return $this->auditLogs()->create([
            'event'      => $event,
            'user_id'    => $userId ?? Auth::id(),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'meta'       => $meta,
        ]);
    }

    /**
     * Convenience: latest N log entries for this model.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AuditLog>
     */
    public function recentAuditLogs(int $limit = 20)
    {
        return $this->auditLogs()->limit($limit)->get();
    }
}
