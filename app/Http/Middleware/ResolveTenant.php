<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request and resolve active tenant.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $tenant = null;

        // 1. Check for explicit X-Tenant-ID header (ULID or numeric ID)
        $headerTenantId = $request->header('X-Tenant-ID');
        if ($headerTenantId) {
            $tenant = TenantContext::withoutTenancy(function () use ($headerTenantId) {
                return Tenant::where('ulid', $headerTenantId)
                    ->orWhere('id', $headerTenantId)
                    ->first();
            });
        }

        // 2. Fall back to user's current_tenant_id
        if (!$tenant && $user->current_tenant_id) {
            $tenant = TenantContext::withoutTenancy(function () use ($user) {
                return Tenant::find($user->current_tenant_id);
            });
        }

        // 3. Fall back to user's first available tenant
        if (!$tenant) {
            $tenant = TenantContext::withoutTenancy(function () use ($user) {
                return $user->tenants()->first();
            });
        }

        // Verify user belongs to the tenant or is super admin
        if ($tenant) {
            if (!$user->isSuperAdmin() && !$user->belongsToTenant($tenant->id)) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.forbidden'),
                ], 403);
            }

            if ($tenant->isSuspended()) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.tenant_suspended'),
                ], 403);
            }

            TenantContext::setTenant($tenant);

            // Sync user's current_tenant_id if different
            if ($user->current_tenant_id !== $tenant->id) {
                $user->forceFill(['current_tenant_id' => $tenant->id])->saveQuietly();
            }
        }

        return $next($request);
    }

    /**
     * Clean up context after request completes.
     */
    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forgetTenant();
    }
}
