<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    use ApiResponse;

    /**
     * List all active SaaS plans.
     */
    public function index(): JsonResponse
    {
        $plans = Plan::where('is_active', true)->get();

        return $this->success(PlanResource::collection($plans));
    }

    /**
     * Get specific plan details.
     */
    public function show(string $slug): JsonResponse
    {
        $plan = Plan::where('slug', $slug)->orWhere('id', $slug)->first();

        if (!$plan) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new PlanResource($plan));
    }
}
