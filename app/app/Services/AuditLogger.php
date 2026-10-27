<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes append-only audit rows (PRD Phase 8, Rec #10) for configuration and
 * classification changes. The actor defaults to the authenticated user; callers
 * acting outside an HTTP request (or on a system flow) can pass an explicit id.
 * Secrets are never stored — callers pass only safe, already-masked properties.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?int $actorId = null,
    ): ActivityLog {
        return ActivityLog::create([
            'actor_id' => $actorId ?? auth()->id(),
            'event' => $event,
            'subject_type' => $subject !== null ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'created_at' => now(),
        ]);
    }
}
