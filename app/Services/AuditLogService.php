<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\TenantContext;

class AuditLogService
{
    /**
     * Record a system audit event log.
     */
    public static function log(
        string $event,
        ?object $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        return AuditLog::create([
            'tenant_id' => TenantContext::getTenantId(),
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
