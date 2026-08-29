<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class SuperAdminController extends Controller
{
    use ApiResponse;

    /**
     * List all tenants across the system for Super Admin.
     */
    public function tenants()
    {
        $tenants = TenantContext::withoutTenancy(fn () => Tenant::with(['subscription.plan'])->latest()->paginate(20));

        return $this->paginated($tenants, TenantResource::class);
    }

    /**
     * Get system-wide platform metrics.
     */
    public function metrics()
    {
        return TenantContext::withoutTenancy(function () {
            $tenantsCount = Tenant::count();
            $activeTenantsCount = Tenant::where('status', 'active')->count();
            $articlesCount = \App\Models\Article::count();
            $usersCount = \App\Models\User::count();

            return $this->success([
                'tenants' => [
                    'total' => $tenantsCount,
                    'active' => $activeTenantsCount,
                ],
                'articles' => [
                    'total' => $articlesCount,
                ],
                'users' => [
                    'total' => $usersCount,
                ],
            ]);
        });
    }

    /**
     * Directly assign or upgrade a plan for a tenant.
     */
    public function assignPlan(Request $request, int $tenantId)
    {
        $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
        ]);

        $tenant = TenantContext::withoutTenancy(fn () => Tenant::find($tenantId));

        if (!$tenant) {
            return $this->error(__('messages.not_found'), 404);
        }

        $plan = Plan::find($request->plan_id);

        TenantContext::withoutTenancy(function () use ($tenant, $plan) {
            Subscription::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        });

        return $this->success(null, 'Plan assigned to tenant successfully.');
    }
}
