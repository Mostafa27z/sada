<?php

namespace Tests\Feature\SaaS;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UsageLimitService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsageLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_plans_can_be_listed_via_api(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'slug', 'price', 'limits', 'features'],
                ],
            ]);
    }

    public function test_usage_limit_service_calculates_remaining_resources(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        $basicPlan = Plan::where('slug', 'basic')->first();

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $basicPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        $service = app(UsageLimitService::class);
        $usage = $service->getUsage($tenant);

        $this->assertEquals(1, $usage['users']['used']);
        $this->assertEquals($basicPlan->max_users, $usage['users']['limit']);
        $this->assertEquals($basicPlan->max_users - 1, $usage['users']['remaining']);
    }

    public function test_usage_api_returns_correct_envelope(): void
    {
        $owner = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        Sanctum::actingAs($owner);

        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/v1/usage');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'plan' => ['name', 'slug'],
                    'users' => ['used', 'limit', 'remaining'],
                    'keywords' => ['used', 'limit', 'remaining'],
                    'sources' => ['used', 'limit', 'remaining'],
                    'articles' => ['used', 'limit', 'remaining'],
                ],
            ]);
    }

    public function test_usage_limit_middleware_blocks_exceeding_resource_creations(): void
    {
        $owner = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        // Attach restrictive plan with max 1 user
        $restrictivePlan = Plan::create([
            'name' => 'خطة مقيدة',
            'slug' => 'restrictive',
            'price' => 0.00,
            'max_users' => 1,
            'max_keywords' => 0,
            'max_sources' => 0,
            'max_articles' => 0,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $restrictivePlan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        // Inviting a 2nd user should fail because max_users = 1 and 1 user (the owner) already exists
        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/v1/users/invite', [
                'email' => 'overlimit@example.com',
                'name' => 'مستخدم إضافي',
            ]);

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }
}
