<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentRole = $this->currentRole();
        $isSuper = $this->isSuperAdmin();

        $role = $isSuper 
            ? 'super_admin' 
            : ($currentRole?->slug ?? 'tenant_owner');

        $roleName = $isSuper
            ? 'مدير النظام الفائق'
            : ($currentRole?->name ?? 'مالك المؤسسة');

        if ($isSuper) {
            $permissions = \App\Models\Permission::pluck('slug')->toArray();
        } elseif ($currentRole) {
            $currentRole->loadMissing('permissions');
            $permissions = $currentRole->permissions->pluck('slug')->toArray();
        } else {
            $permissions = [];
        }

        $tenant = $this->currentTenant;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'role' => $role,
            'role_name' => $roleName,
            'permissions' => array_values(array_unique($permissions)),
            'companyName' => $tenant?->name ?? 'منصة مرآة',
            'current_tenant_id' => $this->current_tenant_id,
            'current_tenant' => $tenant ? [
                'id' => $tenant->id,
                'ulid' => $tenant->ulid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logo' => $tenant->logo,
                'status' => $tenant->status,
                'settings' => $tenant->settings,
                'plan' => $tenant->plan ? new PlanResource($tenant->plan) : null,
                'subscription' => $tenant->subscription ? [
                    'id' => $tenant->subscription->id,
                    'status' => $tenant->subscription->status,
                    'trial_ends_at' => $tenant->subscription->trial_ends_at?->toISOString(),
                    'ends_at' => $tenant->subscription->ends_at?->toISOString(),
                    'billing_cycle' => $tenant->subscription->billing_cycle ?? 'yearly',
                ] : null,
            ] : null,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'last_login_at' => $this->last_login_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
