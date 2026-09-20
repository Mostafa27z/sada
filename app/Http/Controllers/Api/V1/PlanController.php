<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    use ApiResponse;

    /**
     * List all active SaaS plans (or all plans if requested by Super Admin).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Plan::query();
        if (!$request->user()?->isSuperAdmin()) {
            $query->where('is_active', true);
        }

        return $this->success(PlanResource::collection($query->get()));
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

    /**
     * Create a new SaaS Plan.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:plans,slug'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:10'],
            'billing_interval' => ['sometimes', 'string'],
            'limits' => ['sometimes', 'nullable', 'array'],
            'limits.max_users' => ['sometimes', 'nullable', 'integer'],
            'limits.max_keywords' => ['sometimes', 'nullable', 'integer'],
            'limits.max_sources' => ['sometimes', 'nullable', 'integer'],
            'limits.max_articles' => ['sometimes', 'nullable', 'integer'],
            'limits.max_api_requests' => ['sometimes', 'nullable', 'integer'],
            'features' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data = [
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'currency' => $validated['currency'] ?? 'SAR',
            'billing_interval' => $validated['billing_interval'] ?? 'monthly',
            'max_users' => $validated['limits']['max_users'] ?? $request->input('max_users', 5),
            'max_keywords' => $validated['limits']['max_keywords'] ?? $request->input('max_keywords', 15),
            'max_sources' => $validated['limits']['max_sources'] ?? $request->input('max_sources', 50),
            'max_articles' => $validated['limits']['max_articles'] ?? $request->input('max_articles', 10000),
            'max_api_requests' => $validated['limits']['max_api_requests'] ?? $request->input('max_api_requests', 1000),
            'features' => $validated['features'] ?? [],
            'is_active' => $validated['is_active'] ?? true,
        ];

        $plan = Plan::create($data);

        return $this->created(new PlanResource($plan), 'Plan created successfully');
    }

    /**
     * Update an existing SaaS Plan.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $plan = Plan::where('id', $id)->orWhere('slug', $id)->first();

        if (!$plan) {
            return $this->error(__('messages.not_found'), 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:plans,slug,' . $plan->id],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'max:10'],
            'billing_interval' => ['sometimes', 'string'],
            'limits' => ['sometimes', 'nullable', 'array'],
            'features' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['limits'])) {
            $limits = $validated['limits'];
            if (isset($limits['max_users'])) $plan->max_users = $limits['max_users'];
            if (isset($limits['max_keywords'])) $plan->max_keywords = $limits['max_keywords'];
            if (isset($limits['max_sources'])) $plan->max_sources = $limits['max_sources'];
            if (isset($limits['max_articles'])) $plan->max_articles = $limits['max_articles'];
            if (isset($limits['max_api_requests'])) $plan->max_api_requests = $limits['max_api_requests'];
        }

        if (isset($validated['name'])) $plan->name = $validated['name'];
        if (isset($validated['slug'])) $plan->slug = $validated['slug'];
        if (isset($validated['description'])) $plan->description = $validated['description'];
        if (isset($validated['price'])) $plan->price = $validated['price'];
        if (isset($validated['currency'])) $plan->currency = $validated['currency'];
        if (isset($validated['billing_interval'])) $plan->billing_interval = $validated['billing_interval'];
        if (isset($validated['features'])) $plan->features = $validated['features'];
        if (isset($validated['is_active'])) $plan->is_active = $validated['is_active'];

        $plan->save();

        return $this->success(new PlanResource($plan), 'Plan updated successfully');
    }

    /**
     * Delete a SaaS Plan.
     */
    public function destroy(string $id): JsonResponse
    {
        $plan = Plan::where('id', $id)->orWhere('slug', $id)->first();

        if (!$plan) {
            return $this->error(__('messages.not_found'), 404);
        }

        if ($plan->subscriptions()->where('status', 'active')->exists()) {
            return $this->error('Cannot delete plan with active subscriptions.', 422);
        }

        $plan->delete();

        return $this->success(null, 'Plan deleted successfully');
    }

    /**
     * Toggle SaaS Plan active state.
     */
    public function toggleActive(string $id): JsonResponse
    {
        $plan = Plan::where('id', $id)->orWhere('slug', $id)->first();

        if (!$plan) {
            return $this->error(__('messages.not_found'), 404);
        }

        $plan->is_active = !$plan->is_active;
        $plan->save();

        return $this->success(new PlanResource($plan), 'Plan status updated successfully');
    }
}
