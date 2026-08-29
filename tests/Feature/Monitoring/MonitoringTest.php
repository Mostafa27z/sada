<?php

namespace Tests\Feature\Monitoring;

use App\Models\Article;
use App\Models\Collection;
use App\Models\Keyword;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitoringTest extends TestCase
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

    public function test_keyword_crud_and_status_transitions(): void
    {
        // 1. Create Keyword
        $createResponse = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson('/api/v1/keywords', [
                'name' => 'الذكاء الاصطناعي',
                'priority' => 'high',
                'match_type' => 'contains',
            ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.name', 'الذكاء الاصطناعي');

        $keywordId = $createResponse->json('data.id');

        // 2. List Keywords
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/api/v1/keywords')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // 3. Pause Keyword
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson("/api/v1/keywords/{$keywordId}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        // 4. Delete Keyword
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->deleteJson("/api/v1/keywords/{$keywordId}")
            ->assertOk();

        $this->assertSoftDeleted('keywords', ['id' => $keywordId]);
    }

    public function test_sources_support_global_and_private_tenant_scoping(): void
    {
        // Global source (tenant_id = null)
        TenantContext::withoutTenancy(function () {
            Source::create([
                'name' => 'وكالة الأنباء السعودية (واس)',
                'url' => 'https://www.spa.gov.sa',
                'type' => 'news',
            ]);
        });

        // Private source for tenant
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson('/api/v1/sources', [
                'name' => 'مدونة خاصة',
                'url' => 'https://blog.example.com',
                'type' => 'blog',
            ])->assertStatus(201);

        // Fetch sources — should return 2 (1 global + 1 private)
        $response = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/api/v1/sources');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_articles_filtering_and_tenant_isolation(): void
    {
        TenantContext::setTenant($this->tenant);

        $article = Article::create([
            'title' => 'إطلاق استراتيجية الذكاء الاصطناعي',
            'summary' => 'ملخص الخبر',
            'url' => 'https://news.example.com/article-1',
            'sentiment' => 'positive',
            'language' => 'ar',
            'country' => 'SA',
            'published_at' => now(),
        ]);

        $response = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/api/v1/articles?sentiment=positive&search=الذكاء');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'إطلاق استراتيجية الذكاء الاصطناعي');
    }

    public function test_collections_management_and_article_attachment(): void
    {
        TenantContext::setTenant($this->tenant);

        $article = Article::create([
            'title' => 'مقال محتفظ به',
            'url' => 'https://news.example.com/article-saved',
            'published_at' => now(),
        ]);

        // Create Collection
        $collectionResponse = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson('/api/v1/collections', [
                'name' => 'المقالات الهامة',
                'color' => '#FF0000',
            ]);

        $collectionResponse->assertStatus(201);
        $collectionId = $collectionResponse->json('data.id');

        // Add article to collection
        $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson("/api/v1/collections/{$collectionId}/articles/{$article->id}")
            ->assertOk();

        // Retrieve collection with articles
        $showResponse = $this->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson("/api/v1/collections/{$collectionId}");

        $showResponse->assertOk()
            ->assertJsonCount(1, 'data.articles');
    }
}
