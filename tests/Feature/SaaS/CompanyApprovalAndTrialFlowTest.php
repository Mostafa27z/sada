<?php

namespace Tests\Feature\SaaS;

use App\Jobs\InitializeTenantIntelligenceJob;
use App\Models\Collection;
use App\Models\Plan;
use App\Models\PlanRequest;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyApprovalAndTrialFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected Plan $basicPlan;
    protected Plan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([InitializeTenantIntelligenceJob::class]);

        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->superAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->basicPlan = Plan::where('slug', 'basic')->first();
        $this->proPlan = Plan::where('slug', 'pro')->first();
    }

    public function test_super_admin_can_approve_suspended_tenant_and_activate_5_day_trial(): void
    {
        $tenant = Tenant::create([
            'name' => 'شركة تجريبية',
            'status' => Tenant::STATUS_SUSPENDED,
        ]);
        $owner = User::factory()->create([
            'status' => User::STATUS_SUSPENDED,
            'current_tenant_id' => $tenant->id,
        ]);
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson("/api/v1/admin/tenants/{$tenant->id}/approve");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $tenant->refresh();
        $owner->refresh();

        $this->assertEquals(Tenant::STATUS_TRIAL, $tenant->status);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue($tenant->trial_ends_at->isFuture());
        $this->assertEquals(User::STATUS_ACTIVE, $owner->status);

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'status' => Subscription::STATUS_TRIAL,
        ]);

        Queue::assertPushed(InitializeTenantIntelligenceJob::class);
    }

    public function test_trial_tenant_is_limited_to_5_campaigns_and_25_comments_per_campaign(): void
    {
        $tenant = Tenant::create([
            'name' => 'شركة تجربة 5 أيام',
            'status' => Tenant::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(5),
            'plan_id' => $this->basicPlan->id,
        ]);
        $owner = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'current_tenant_id' => $tenant->id,
        ]);
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        Sanctum::actingAs($owner);

        // Create 5 campaigns (should succeed and cap comments_limit at 25)
        for ($i = 1; $i <= 5; $i++) {
            $resp = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
                ->postJson('/api/v1/collections', [
                    'name' => "حملة رقم {$i}",
                    'link' => "https://x.com/post/{$i}",
                    'comments_limit' => 100, // Client requests 100
                ]);

            $resp->assertStatus(201);
            $this->assertEquals(25, $resp->json('data.comments_limit'));
        }

        // Creating 6th campaign must fail with 403
        $resp6 = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/v1/collections', [
                'name' => 'حملة رقم 6',
                'link' => 'https://x.com/post/6',
            ]);

        $resp6->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_expired_trial_blocks_mutating_actions(): void
    {
        $tenant = Tenant::create([
            'name' => 'شركة منتهية الصلاحية',
            'status' => Tenant::STATUS_TRIAL,
            'trial_ends_at' => now()->subDay(), // Expired
            'plan_id' => $this->basicPlan->id,
        ]);
        $owner = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'current_tenant_id' => $tenant->id,
        ]);
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        Sanctum::actingAs($owner);

        // Attempting to create a keyword should be blocked
        $resp = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/v1/keywords', [
                'name' => 'كلمة تجريبية',
            ]);

        $resp->assertStatus(403)
            ->assertJson(['code' => 'trial_expired']);
    }

    public function test_client_can_request_plan_and_super_admin_approves_it(): void
    {
        $tenant = Tenant::create([
            'name' => 'شركة رائدة',
            'status' => Tenant::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(2),
        ]);
        $owner = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'current_tenant_id' => $tenant->id,
        ]);
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        // 1. Client requests Pro Plan
        Sanctum::actingAs($owner);
        $requestResp = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/v1/plans/request', [
                'plan_id' => $this->proPlan->id,
                'notes' => 'نود الترقية للباقة الاحترافية مع الأخبار',
            ]);

        $requestResp->assertStatus(201);
        $requestId = $requestResp->json('data.id');

        $this->assertDatabaseHas('plan_requests', [
            'id' => $requestId,
            'tenant_id' => $tenant->id,
            'plan_id' => $this->proPlan->id,
            'status' => PlanRequest::STATUS_PENDING,
        ]);

        // 2. Super Admin approves the request
        Sanctum::actingAs($this->superAdmin);
        $approveResp = $this->postJson("/api/v1/admin/plan-requests/{$requestId}/approve");

        $approveResp->assertOk();

        $tenant->refresh();
        $this->assertEquals(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertEquals($this->proPlan->id, $tenant->plan_id);

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $this->proPlan->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_news_feature_is_restricted_when_plan_does_not_include_news(): void
    {
        $tenant = Tenant::create([
            'name' => 'شركة باقة أساسية بدون أخبار',
            'status' => Tenant::STATUS_ACTIVE,
            'plan_id' => $this->basicPlan->id, // has_news = false
        ]);
        $owner = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'current_tenant_id' => $tenant->id,
        ]);
        $tenant->users()->attach($owner->id, ['is_owner' => true]);

        Sanctum::actingAs($owner);

        // Basic Plan has has_news = false -> Should be blocked with 403
        $resp = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/v1/industry-news/today');

        $resp->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'feature_not_included',
            ]);

        // Upgrade tenant to Pro Plan (has_news = true)
        $tenant->update(['plan_id' => $this->proPlan->id]);

        $respAllowed = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/v1/industry-news/today');

        $respAllowed->assertOk();
    }
}
