<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Sada-Signature');

        if (!$signature) {
            return response()->json([
                'success' => false,
                'message' => 'Missing webhook signature header',
            ], 401);
        }

        $secret = config('sada.webhook_secret', 'default_sada_webhook_secret');
        $expectedSignature = hash_hmac('sha256', $request->getContent(), $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature',
            ], 403);
        }

        return $next($request);
    }
}
