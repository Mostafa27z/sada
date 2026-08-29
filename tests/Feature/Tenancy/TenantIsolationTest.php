<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Dummy Tenant-Owned Model for Isolation Testing
class DummyTestPost extends Model
{
    use BelongsToTenant;

    protected $table = 'dummy_test_posts';
    protected $fillable = ['title', 'tenant_id'];
}

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dummy_test_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function test_tenant_a_cannot_access_tenant_b_data(): void
    {
        $tenantA = Tenant::factory()->create(['name' => 'شركة A']);
        $userA = User::factory()->create();
        $tenantA->users()->attach($userA->id, ['is_owner' => true]);

        $tenantB = Tenant::factory()->create(['name' => 'شركة B']);
        $userB = User::factory()->create();
        $tenantB->users()->attach($userB->id, ['is_owner' => true]);

        // Create posts for Tenant A
        TenantContext::withoutTenancy(function () use ($tenantA) {
            DummyTestPost::create(['tenant_id' => $tenantA->id, 'title' => 'خبر خاص بالشركة A']);
        });

        // Create posts for Tenant B
        TenantContext::withoutTenancy(function () use ($tenantB) {
            DummyTestPost::create(['tenant_id' => $tenantB->id, 'title' => 'خبر خاص بالشركة B']);
        });

        // Query under Tenant A context
        TenantContext::setTenant($tenantA);
        $postsA = DummyTestPost::all();

        $this->assertCount(1, $postsA);
        $this->assertEquals('خبر خاص بالشركة A', $postsA->first()->title);

        // Query under Tenant B context
        TenantContext::setTenant($tenantB);
        $postsB = DummyTestPost::all();

        $this->assertCount(1, $postsB);
        $this->assertEquals('خبر خاص بالشركة B', $postsB->first()->title);
    }

    public function test_user_cannot_access_unowned_tenant_via_header(): void
    {
        $tenantA = Tenant::factory()->create();
        $userA = User::factory()->create();
        $tenantA->users()->attach($userA->id, ['is_owner' => true]);

        $tenantB = Tenant::factory()->create();

        Sanctum::actingAs($userA);

        $response = $this->withHeader('X-Tenant-ID', (string) $tenantB->id)
            ->getJson('/api/v1/users');

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_query_returns_empty_when_no_tenant_resolved(): void
    {
        $tenant = Tenant::factory()->create();
        TenantContext::withoutTenancy(function () use ($tenant) {
            DummyTestPost::create(['tenant_id' => $tenant->id, 'title' => 'خبر سري']);
        });

        TenantContext::forgetTenant();

        $posts = DummyTestPost::all();
        $this->assertCount(0, $posts);
    }
}
