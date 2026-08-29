<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_tenant_workspace(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tenants', [
            'name' => 'شركة الإعلام الحديث',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'ulid', 'name', 'slug', 'status'],
            ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'شركة الإعلام الحديث',
        ]);

        $this->assertTrue($user->belongsToTenant($response->json('data.id')));
    }

    public function test_user_can_list_their_tenants(): void
    {
        $user = User::factory()->create();
        $tenant1 = Tenant::factory()->create(['name' => 'شركة 1']);
        $tenant2 = Tenant::factory()->create(['name' => 'شركة 2']);

        $tenant1->users()->attach($user->id, ['is_owner' => true]);
        $tenant2->users()->attach($user->id, ['is_owner' => false]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/tenants');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_can_switch_tenant(): void
    {
        $user = User::factory()->create();
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        $tenant1->users()->attach($user->id, ['is_owner' => true]);
        $tenant2->users()->attach($user->id, ['is_owner' => false]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/tenants/' . $tenant2->id . '/switch');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $user->refresh();
        $this->assertEquals($tenant2->id, $user->current_tenant_id);
    }

    public function test_tenant_owner_can_invite_and_remove_users(): void
    {
        $owner = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->id, ['is_owner' => true]);
        $owner->forceFill(['current_tenant_id' => $tenant->id])->save();

        Sanctum::actingAs($owner);

        // Invite user
        $inviteResponse = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/v1/users/invite', [
                'email' => 'analyst@example.com',
                'name' => 'محلل بيانات',
            ]);

        $inviteResponse->assertStatus(201);
        $invitedUserId = $inviteResponse->json('data.id');

        // List tenant users
        $listResponse = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/v1/users');

        $listResponse->assertOk()
            ->assertJsonCount(2, 'data');

        // Remove user
        $removeResponse = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->deleteJson('/api/v1/users/' . $invitedUserId);

        $removeResponse->assertOk();

        // Verify user removed from tenant
        $listResponseAfter = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/v1/users');

        $listResponseAfter->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
