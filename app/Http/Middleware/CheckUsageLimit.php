<?php

namespace App\Http\Middleware;

use App\Services\UsageLimitService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUsageLimit
{
    public function __construct(protected UsageLimitService $usageLimitService)
    {
    }

    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $next($request);
        }

        $canProceed = match ($resource) {
            'users' => $this->usageLimitService->canCreateUser($tenant),
            'keywords' => $this->usageLimitService->canCreateKeyword($tenant),
            'sources' => $this->usageLimitService->canCreateSource($tenant),
            'articles' => $this->usageLimitService->canStoreArticle($tenant),
            default => true,
        };

        if (!$canProceed) {
            return response()->json([
                'success' => false,
                'message' => __('messages.usage_limit_reached', ['resource' => $resource]),
            ], 403);
        }

        return $next($request);
    }
}
