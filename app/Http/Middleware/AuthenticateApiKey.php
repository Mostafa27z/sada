<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-API-Key');

        if (!$key) {
            return response()->json([
                'success' => false,
                'message' => 'Missing X-API-Key header',
            ], 401);
        }

        $keyHash = hash('sha256', $key);

        $apiKey = TenantContext::withoutTenancy(function () use ($keyHash) {
            return ApiKey::where('key_hash', $keyHash)->first();
        });

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API Key',
            ], 401);
        }

        if ($apiKey->expires_at && $apiKey->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'API Key expired',
            ], 401);
        }

        $apiKey->update(['last_used_at' => now()]);

        // Resolve active tenant from API key
        if ($apiKey->tenant_id) {
            TenantContext::setTenantId($apiKey->tenant_id);
        }

        return $next($request);
    }
}
