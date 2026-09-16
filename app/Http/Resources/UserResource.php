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
        $role = $this->isSuperAdmin() 
            ? 'super_admin' 
            : ($this->currentRole()?->slug ?? 'tenant_owner');

        $tenant = $this->currentTenant;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'role' => $role,
            'companyName' => $tenant?->name ?? 'منصة صدى',
            'current_tenant_id' => $this->current_tenant_id,
            'current_tenant' => $tenant ? [
                'id' => $tenant->id,
                'ulid' => $tenant->ulid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'last_login_at' => $this->last_login_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
