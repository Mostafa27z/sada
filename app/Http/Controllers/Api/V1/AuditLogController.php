<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use App\Support\TenantContext;

class AuditLogController extends Controller
{
    use ApiResponse;

    public function index()
    {
        $tenantId = TenantContext::getTenantId();

        $logs = AuditLog::where('tenant_id', $tenantId)
            ->latest()
            ->paginate(request('per_page', 20));

        return $this->paginated($logs, AuditLogResource::class);
    }
}
