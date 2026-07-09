<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Deliberately minimal — a single record() call, so wiring it into an
 * existing controller is a one-line addition rather than a refactor.
 * $actorId is nullable to cover system-initiated actions (e.g. a webhook
 * finalizing a payment with no authenticated user in the request).
 */
class AuditLogService
{
    public function record(
        ?string $actorId,
        string $action,
        string $entityType,
        string $entityId,
        ?Request $request = null,
    ): void {
        AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }
}
