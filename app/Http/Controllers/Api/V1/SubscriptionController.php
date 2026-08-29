<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Services\UsageLimitService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(protected UsageLimitService $usageLimitService)
    {
    }

    /**
     * Get active subscription details for current tenant.
     */
    public function show(): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $subscription = TenantContext::withoutTenancy(function () use ($tenant) {
            return $tenant->subscription()->with('plan')->first();
        });

        if (!$subscription) {
            return $this->success(null, __('messages.no_active_subscription'));
        }

        return $this->success(new SubscriptionResource($subscription));
    }

    /**
     * Get usage statistics for current tenant workspace.
     */
    public function usage(): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $usageData = $this->usageLimitService->getUsage($tenant);

        return $this->success($usageData);
    }
}
