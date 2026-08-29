<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Support\ApiResponse;
use App\Support\TenantContext;

class DashboardController extends Controller
{
    use ApiResponse;

    public function stats(DashboardService $service)
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $stats = $service->getStats($tenant);

        return $this->success($stats);
    }
}
