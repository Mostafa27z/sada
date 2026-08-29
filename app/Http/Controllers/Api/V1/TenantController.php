<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenants\CreateTenantRequest;
use App\Http\Requests\Tenants\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\TenantService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    use ApiResponse;

    public function __construct(protected TenantService $tenantService)
    {
    }

    /**
     * List all tenants available to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $tenants = TenantContext::withoutTenancy(function () use ($request) {
            return $request->user()->tenants()->get();
        });

        return $this->success(TenantResource::collection($tenants));
    }

    /**
     * Create a new tenant workspace.
     */
    public function store(CreateTenantRequest $request): JsonResponse
    {
        $tenant = $this->tenantService->createTenant(
            $request->user(),
            $request->validated()
        );

        return $this->created(
            new TenantResource($tenant),
            __('messages.created')
        );
    }

    /**
     * Display the current or requested tenant details.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = TenantContext::withoutTenancy(function () use ($id) {
            return Tenant::where('ulid', $id)->orWhere('id', $id)->first();
        });

        if (!$tenant || (!$request->user()->isSuperAdmin() && !$request->user()->belongsToTenant($tenant->id))) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new TenantResource($tenant));
    }

    /**
     * Update tenant details.
     */
    public function update(UpdateTenantRequest $request, string $id): JsonResponse
    {
        $tenant = TenantContext::withoutTenancy(function () use ($id) {
            return Tenant::where('ulid', $id)->orWhere('id', $id)->first();
        });

        if (!$tenant || (!$request->user()->isSuperAdmin() && !$request->user()->belongsToTenant($tenant->id))) {
            return $this->error(__('messages.not_found'), 404);
        }

        TenantContext::withoutTenancy(function () use ($tenant, $request) {
            $tenant->update($request->validated());
        });

        return $this->success(
            new TenantResource($tenant->fresh()),
            __('messages.updated')
        );
    }

    /**
     * Switch active tenant context for the user.
     */
    public function switch(Request $request, string $id): JsonResponse
    {
        $tenant = TenantContext::withoutTenancy(function () use ($id) {
            return Tenant::where('ulid', $id)->orWhere('id', $id)->first();
        });

        if (!$tenant || !$request->user()->belongsToTenant($tenant->id)) {
            return $this->error(__('messages.forbidden'), 403);
        }

        $request->user()->switchTenant($tenant->id);
        TenantContext::setTenant($tenant);

        return $this->success([
            'current_tenant' => new TenantResource($tenant),
        ], __('messages.tenant_switched'));
    }
}
