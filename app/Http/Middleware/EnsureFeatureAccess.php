<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureAccess
{
    /**
     * Handle an incoming request and check if current tenant's plan supports the required feature.
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $next($request);
        }

        // Resolve active plan
        $plan = $tenant->plan;
        if (!$plan && $tenant->subscription && $tenant->subscription->isActive()) {
            $plan = $tenant->subscription->plan;
        }

        $hasAccess = match ($feature) {
            'news' => (bool) ($plan?->has_news ?? false),
            default => true,
        };

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'code' => 'feature_not_included',
                'message' => $feature === 'news'
                    ? __('messages.news_feature_not_included')
                    : __('messages.feature_not_included', ['feature' => $feature]),
            ], 403);
        }

        return $next($request);
    }
}
