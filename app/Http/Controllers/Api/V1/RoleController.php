<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    use ApiResponse;

    /**
     * List all available roles (System Roles + Tenant Custom Roles).
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $roles = Role::where('slug', '!=', Role::SUPER_ADMIN)
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id');
                if ($tenantId) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->with('permissions')
            ->orderBy('is_system', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        if ($tenantId) {
            $userCounts = DB::table('tenant_user')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('role_id')
                ->select('role_id', DB::raw('count(*) as count'))
                ->groupBy('role_id')
                ->pluck('count', 'role_id');

            $ownerCount = DB::table('tenant_user')
                ->where('tenant_id', $tenantId)
                ->where('is_owner', true)
                ->count();

            foreach ($roles as $role) {
                if ($role->slug === Role::TENANT_OWNER) {
                    $role->users_count = $ownerCount;
                } else {
                    $role->users_count = $userCounts[$role->id] ?? 0;
                }
            }
        }

        return $this->success(RoleResource::collection($roles));
    }

    /**
     * List all system permissions categorized.
     */
    public function permissions(Request $request): JsonResponse
    {
        $permissions = Permission::orderBy('category')
            ->orderBy('id', 'asc')
            ->get();

        return $this->success(PermissionResource::collection($permissions));
    }

    /**
     * Store a new custom role for the active tenant.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        if (!$tenantId) {
            return $this->error('لم يتم تحديد مساحة العمل النشطة.', 400);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'permissions' => 'required|array|min:1',
            'permissions.*' => 'required',
        ], [
            'name.required' => 'اسم الدور مطلوب.',
            'permissions.required' => 'يجب تحديد صلاحية واحدة على الأقل لهذا الدور.',
            'permissions.min' => 'يجب تحديد صلاحية واحدة على الأقل لهذا الدور.',
        ]);

        // Generate clean tenant-scoped slug
        $baseSlug = Str::slug($validated['name']);
        if (empty($baseSlug)) {
            $baseSlug = 'role';
        }
        $slug = $baseSlug . '-' . $tenantId . '-' . Str::lower(Str::random(4));

        $role = Role::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'is_system' => false,
        ]);

        // Resolve permission IDs (supports both ID integers or slug strings)
        $permissionIds = $this->resolvePermissionIds($validated['permissions']);
        $role->permissions()->sync($permissionIds);

        $role->users_count = 0;

        return $this->success(
            new RoleResource($role->load('permissions')),
            'تم إنشاء الدور وتعيين الصلاحيات بنجاح.',
            201
        );
    }

    /**
     * Show role details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $role = Role::where('slug', '!=', Role::SUPER_ADMIN)
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id');
                if ($tenantId) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })->with('permissions')->findOrFail($id);

        return $this->success(new RoleResource($role));
    }

    /**
     * Update custom tenant role.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        if (!$tenantId) {
            return $this->error('لم يتم تحديد مساحة العمل النشطة.', 400);
        }

        $role = Role::where('tenant_id', $tenantId)->findOrFail($id);

        if ($role->is_system) {
            return $this->error('لا يمكن تعديل أدوار النظام الأساسية.', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'permissions' => 'required|array|min:1',
            'permissions.*' => 'required',
        ], [
            'name.required' => 'اسم الدور مطلوب.',
            'permissions.required' => 'يجب تحديد صلاحية واحدة على الأقل لهذا الدور.',
            'permissions.min' => 'يجب تحديد صلاحية واحدة على الأقل لهذا الدور.',
        ]);

        $role->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $permissionIds = $this->resolvePermissionIds($validated['permissions']);
        $role->permissions()->sync($permissionIds);

        return $this->success(
            new RoleResource($role->fresh()->load('permissions')),
            'تم تحديث بيانات الدور والصلاحيات بنجاح.'
        );
    }

    /**
     * Delete custom tenant role.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        if (!$tenantId) {
            return $this->error('لم يتم تحديد مساحة العمل النشطة.', 400);
        }

        $role = Role::where('tenant_id', $tenantId)->findOrFail($id);

        if ($role->is_system) {
            return $this->error('لا يمكن حذف أدوار النظام الأساسية.', 403);
        }

        // Safety check: Ensure no users currently assigned to this custom role
        $hasUsers = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('role_id', $id)
            ->exists();

        if ($hasUsers) {
            return $this->error('لا يمكن حذف هذا الدور لوجود مستخدمين مرتبطين به حالياً. يرجى نقل المستخدمين إلى دور آخر أولاً.', 422);
        }

        $role->permissions()->detach();
        $role->delete();

        return $this->success(null, 'تم حذف الدور بنجاح.');
    }

    /**
     * Resolve permission IDs from an array of IDs or slugs.
     */
    protected function resolvePermissionIds(array $permissions): array
    {
        $isNumeric = !empty($permissions) && is_numeric($permissions[0]);

        if ($isNumeric) {
            return Permission::whereIn('id', $permissions)->pluck('id')->toArray();
        }

        return Permission::whereIn('slug', $permissions)->pluck('id')->toArray();
    }
}
