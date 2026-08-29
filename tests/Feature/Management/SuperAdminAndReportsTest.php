<?php

namespace Tests\Feature\Management;

use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SuperAdminAndReportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->tenant = Tenant::factory()->create();
        $this->tenant->users()->attach($this->user->id, ['is_owner' => true]);
        $this->user->forceFill(['current_tenant_id' => $this->tenant->id])->save();

        Sanctum::actingAs($this->user);
    }

    public function test_report_creation_and_download(): void
    {
        // 1. Create Report
        $response = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson('/api/v1/reports', [
                'name' => 'التقرير التنفيذي الشهري',
                'type' => 'executive',
                'format' => 'json',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'التقرير التنفيذي الشهري')
            ->assertJsonPath('data.status', 'completed');

        $reportId = $response->json('data.id');

        // 2. Download Report
        $downloadResponse = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->get("/api/v1/reports/{$reportId}/download");

        $downloadResponse->assertOk();
    }

    public function test_api_keys_issuance_and_authentication(): void
    {
        // Issue API key
        $response = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson('/api/v1/api-keys', [
                'name' => 'مفتاح المطورين للمواكبة',
            ]);

        $response->assertStatus(201);
        $plainKey = $response->json('data.plain_key');
        $this->assertNotNull($plainKey);

        // List keys
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/api/v1/api-keys')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_audit_logs_recording(): void
    {
        TenantContext::setTenant($this->tenant);

        // Log audit event
        AuditLogService::log('keyword.created', null, null, ['name' => 'كلمة مراجعة']);

        $response = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/api/v1/audit-logs');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'keyword.created');
    }

    public function test_super_admin_tenants_and_plan_assignment(): void
    {
        $plan = Plan::create([
            'name' => 'باقة VIP',
            'slug' => 'vip-plan',
            'price' => 500.00,
            'currency' => 'SAR',
            'billing_interval' => 'monthly',
        ]);

        // List all tenants via admin endpoint
        $this->getJson('/api/v1/admin/tenants')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Assign plan to tenant
        $this->postJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", [
            'plan_id' => $plan->id,
        ])->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
        ]);
    }
}
