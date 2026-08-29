<?php

namespace Tests\Feature\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_roles_and_permissions_are_seeded_properly(): void
    {
        $this->assertDatabaseHas('roles', ['slug' => Role::SUPER_ADMIN]);
        $this->assertDatabaseHas('roles', ['slug' => Role::TENANT_OWNER]);
        $this->assertDatabaseHas('roles', ['slug' => Role::ANALYST]);
        $this->assertDatabaseHas('permissions', ['slug' => 'manage_keywords']);
        $this->assertDatabaseHas('permissions', ['slug' => 'view_dashboard']);
    }

    public function test_tenant_owner_has_all_permissions(): void
    {
        $owner = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        TenantContext::setTenant($tenant);

        $this->assertTrue($owner->hasPermission('manage_keywords'));
        $this->assertTrue($owner->hasPermission('manage_users'));
        $this->assertTrue($owner->hasPermission('view_dashboard'));
    }

    public function test_analyst_has_restricted_permissions(): void
    {
        $analyst = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $analystRole = Role::where('slug', Role::ANALYST)->first();

        $tenant->users()->attach($analyst->id, [
            'is_owner' => false,
            'role_id' => $analystRole->id,
        ]);

        TenantContext::setTenant($tenant);

        $this->assertTrue($analyst->hasPermission('manage_keywords'));
        $this->assertTrue($analyst->hasPermission('view_articles'));
        $this->assertFalse($analyst->hasPermission('manage_users'));
        $this->assertFalse($analyst->hasPermission('manage_billing'));
    }

    public function test_user_role_assignment_via_api(): void
    {
        $owner = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        $member = User::factory()->create();
        $tenant->users()->attach($member->id, ['is_owner' => false]);

        Sanctum::actingAs($owner);

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/v1/users/' . $member->id . '/roles', [
                'role' => Role::ANALYST,
            ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        TenantContext::setTenant($tenant);
        $this->assertTrue($member->hasRole(Role::ANALYST));
    }

    public function test_gate_checks_enforce_permissions(): void
    {
        $viewer = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $viewerRole = Role::where('slug', Role::VIEWER)->first();

        $tenant->users()->attach($viewer->id, [
            'is_owner' => false,
            'role_id' => $viewerRole->id,
        ]);
        $viewer->forceFill(['current_tenant_id' => $tenant->id])->save();

        TenantContext::setTenant($tenant);

        $this->assertTrue(Gate::forUser($viewer)->allows('view_dashboard'));
        $this->assertFalse(Gate::forUser($viewer)->allows('manage_keywords'));
    }
}
