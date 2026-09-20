<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantRequestController extends Controller
{
    use ApiResponse;

    /**
     * List company join requests (Super Admin).
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $query = TenantRequest::with('requestedPlan')->latest();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $requests = $query->paginate(20);

        return $this->paginated($requests);
    }

    /**
     * Submit a new company registration request (Public or Admin).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'sector' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'requested_plan_id' => ['sometimes', 'nullable', 'exists:plans,id'],
            'commercial_register' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $tenantRequest = TenantRequest::create($validated);

        return $this->created($tenantRequest, 'Tenant registration request submitted successfully.');
    }

    /**
     * Approve a company join request and auto-provision workspace.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $tenantRequest = TenantRequest::find($id);

        if (!$tenantRequest) {
            return $this->error(__('messages.not_found'), 404);
        }

        if ($tenantRequest->status === 'approved') {
            return $this->error('Tenant request is already approved.', 400);
        }

        $validated = $request->validate([
            'plan_id' => ['sometimes', 'nullable', 'exists:plans,id'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $planId = $validated['plan_id'] ?? $tenantRequest->requested_plan_id ?? Plan::first()?->id;

        return DB::transaction(function () use ($tenantRequest, $planId, $validated) {
            // 1. Create Workspace Tenant
            $tenant = TenantContext::withoutTenancy(function () use ($tenantRequest, $planId) {
                return Tenant::create([
                    'ulid' => (string) Str::ulid(),
                    'name' => $tenantRequest->company_name,
                    'slug' => Str::slug($tenantRequest->company_name) . '-' . Str::random(5),
                    'status' => Tenant::STATUS_ACTIVE,
                    'plan_id' => $planId,
                ]);
            });

            // 2. Create Active Subscription
            if ($planId) {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $planId,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => now()->addMonth(),
                ]);
            }

            // 3. Create or update user account for contact
            $user = User::firstOrCreate(
                ['email' => $tenantRequest->contact_email],
                [
                    'name' => $tenantRequest->contact_name,
                    'phone' => $tenantRequest->contact_phone,
                    'password' => Hash::make(Str::random(12)),
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                    'current_tenant_id' => $tenant->id,
                ]
            );

            $ownerRole = Role::where('slug', Role::TENANT_OWNER)->first();
            $tenant->users()->syncWithoutDetaching([
                $user->id => [
                    'role_id' => $ownerRole?->id,
                    'is_owner' => true,
                ]
            ]);

            // 4. Update request status
            $tenantRequest->update([
                'status' => 'approved',
                'created_tenant_id' => $tenant->id,
                'notes' => $validated['notes'] ?? $tenantRequest->notes,
            ]);

            return $this->success([
                'tenant_id' => $tenant->id,
                'company_name' => $tenant->name,
                'status' => 'active',
            ], 'Tenant request approved and workspace created successfully');
        });
    }

    /**
     * Reject a company join request.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $tenantRequest = TenantRequest::find($id);

        if (!$tenantRequest) {
            return $this->error(__('messages.not_found'), 404);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $tenantRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
        ]);

        return $this->success(null, 'Tenant request rejected successfully');
    }
}
