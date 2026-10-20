<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_tenant_admin_can_list_roles_and_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $adminRole = Role::where('slug', Role::TENANT_ADMIN)->first();
        $admin->tenants()->attach($tenant->id, ['role_id' => $adminRole->id, 'is_owner' => false]);

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/roles');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($response->json('data'));
        $roleSlugs = collect($response->json('data'))->pluck('slug')->toArray();
        $this->assertNotContains(Role::SUPER_ADMIN, $roleSlugs);

        // Permissions endpoint
        $permResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/permissions');

        $permResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $slugs = collect($permResponse->json('data'))->pluck('slug')->toArray();
        $this->assertContains('view_dashboard', $slugs);
        $this->assertContains('view_locations', $slugs);
        $this->assertContains('manage_locations', $slugs);
        $this->assertContains('view_industry_news', $slugs);
    }

    public function test_tenant_admin_can_create_custom_role(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $adminRole = Role::where('slug', Role::TENANT_ADMIN)->first();
        $admin->tenants()->attach($tenant->id, ['role_id' => $adminRole->id, 'is_owner' => false]);

        $payload = [
            'name' => 'مسؤول الفروع الميدانية',
            'description' => 'صلاحية استعراض وإدارة خرائط جوجل والتقييمات فقط',
            'permissions' => ['view_dashboard', 'view_locations', 'manage_locations'],
        ];

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/roles', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'مسؤول الفروع الميدانية')
            ->assertJsonPath('data.is_system', false);

        $createdRoleId = $response->json('data.id');
        $this->assertDatabaseHas('roles', [
            'id' => $createdRoleId,
            'tenant_id' => $tenant->id,
            'name' => 'مسؤول الفروع الميدانية',
        ]);

        $createdRole = Role::find($createdRoleId);
        $this->assertCount(3, $createdRole->permissions);
    }

    public function test_cannot_delete_system_role_or_role_with_assigned_users(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $adminRole = Role::where('slug', Role::TENANT_ADMIN)->first();
        $admin->tenants()->attach($tenant->id, ['role_id' => $adminRole->id, 'is_owner' => false]);

        // Attempt deleting system role
        $sysDelete = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->deleteJson("/api/v1/roles/{$adminRole->id}");

        $sysDelete->assertStatus(404); // Scoped to tenant_id so system role returns 404/403

        // Create custom role
        $customRole = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'مختبر',
            'slug' => 'tester-' . $tenant->id,
            'is_system' => false,
        ]);
        $customRole->permissions()->sync(Permission::where('slug', 'view_dashboard')->pluck('id'));

        // Assign user to this custom role
        $member = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $member->tenants()->attach($tenant->id, ['role_id' => $customRole->id, 'is_owner' => false]);

        // Try deleting role that has assigned users
        $blockedDelete = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->deleteJson("/api/v1/roles/{$customRole->id}");

        $blockedDelete->assertStatus(422);

        // Remove user assignment and delete successfully
        $member->tenants()->updateExistingPivot($tenant->id, ['role_id' => null]);

        $successDelete = $this->actingAs($admin)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->deleteJson("/api/v1/roles/{$customRole->id}");

        $successDelete->assertStatus(200);
        $this->assertDatabaseMissing('roles', ['id' => $customRole->id]);
    }

    public function test_user_resource_and_me_endpoint_return_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);

        $customRole = Role::create([
            'tenant_id' => $tenant->id,
            'name' => 'مسؤول خرائط',
            'slug' => 'maps-lead-' . $tenant->id,
            'is_system' => false,
        ]);
        $customRole->permissions()->sync(
            Permission::whereIn('slug', ['view_dashboard', 'view_locations'])->pluck('id')
        );

        $user->tenants()->attach($tenant->id, ['role_id' => $customRole->id, 'is_owner' => false]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/me');

        $response->assertStatus(200);
        $permissions = $response->json('data.permissions');
        $this->assertIsArray($permissions);
        $this->assertContains('view_dashboard', $permissions);
        $this->assertContains('view_locations', $permissions);
        $this->assertNotContains('manage_users', $permissions);
    }
}
