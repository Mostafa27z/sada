<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Reject requests from suspended or inactive users.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->isActive()) {
            // Revoke the current token
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => __('messages.account_not_active'),
            ], 403);
        }

        return $next($request);
    }
}
