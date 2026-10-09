<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanRequestController extends Controller
{
    use ApiResponse;

    /**
     * Submit a plan change / upgrade request (Tenant Client).
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $plan = Plan::find($validated['plan_id']);

        if (!$plan || !$plan->is_active) {
            return $this->error('الباقة المطلوبة غير متوفرة أو غير نشطة حالياً.', 422);
        }

        // Prevent duplicate pending requests for the same plan
        $existingPending = PlanRequest::where('tenant_id', $tenant->id)
            ->where('status', PlanRequest::STATUS_PENDING)
            ->first();

        if ($existingPending) {
            return $this->error('لديك بالفعل طلب باقة معلق قيد المراجعة حالياً من قبل الإدارة.', 422);
        }

        $planRequest = PlanRequest::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'user_id' => $request->user()->id,
            'status' => PlanRequest::STATUS_PENDING,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->created(
            $planRequest->load(['plan', 'user']),
            __('messages.plan_request_submitted')
        );
    }

    /**
     * List all plan requests for the current tenant workspace.
     */
    public function myRequests(): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $requests = PlanRequest::where('tenant_id', $tenant->id)
            ->with(['plan', 'reviewer:id,name,email'])
            ->latest()
            ->get();

        return $this->success($requests);
    }

    /**
     * List all tenant plan requests across the platform (Super Admin).
     */
    public function index(Request $request): JsonResponse
    {
        $query = PlanRequest::with(['tenant', 'plan', 'user:id,name,email', 'reviewer:id,name,email'])->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $requests = $query->paginate(20);

        return $this->paginated($requests);
    }

    /**
     * Approve a tenant plan request and activate subscription (Super Admin).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $planRequest = PlanRequest::with(['tenant', 'plan'])->find($id);

        if (!$planRequest) {
            return $this->error(__('messages.not_found'), 404);
        }

        if ($planRequest->status !== PlanRequest::STATUS_PENDING) {
            return $this->error('تمت معالجة هذا الطلب مسبقاً.', 422);
        }

        return DB::transaction(function () use ($planRequest, $request) {
            $tenant = $planRequest->tenant;
            $plan = $planRequest->plan;

            // 1. Mark request as approved
            $planRequest->update([
                'status' => PlanRequest::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            // 2. Upgrade tenant to the requested plan and set status to active
            $tenant->update([
                'plan_id' => $plan->id,
                'status' => Tenant::STATUS_ACTIVE,
            ]);

            // 3. Create or update active subscription
            $durationDays = $plan->billing_interval === 'yearly' ? 365 : 30;

            Subscription::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan_id' => $plan->id,
                    'status' => Subscription::STATUS_ACTIVE,
                    'starts_at' => now(),
                    'ends_at' => now()->addDays($durationDays),
                    'trial_ends_at' => null,
                ]
            );

            return $this->success(
                $planRequest->fresh(['tenant.plan', 'reviewer']),
                __('messages.plan_request_approved')
            );
        });
    }

    /**
     * Reject a tenant plan request (Super Admin).
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $planRequest = PlanRequest::find($id);

        if (!$planRequest) {
            return $this->error(__('messages.not_found'), 404);
        }

        if ($planRequest->status !== PlanRequest::STATUS_PENDING) {
            return $this->error('تمت معالجة هذا الطلب مسبقاً.', 422);
        }

        $planRequest->update([
            'status' => PlanRequest::STATUS_REJECTED,
            'rejection_reason' => $validated['rejection_reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return $this->success(
            $planRequest->fresh(['tenant', 'plan', 'reviewer']),
            __('messages.plan_request_rejected')
        );
    }
}
