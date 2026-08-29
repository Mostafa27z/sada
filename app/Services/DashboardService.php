<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\Source;
use App\Models\Tenant;
use App\Support\TenantContext;

class DashboardService
{
    /**
     * Get high-level dashboard statistics envelope for tenant.
     */
    public function getStats(Tenant $tenant): array
    {
        return TenantContext::withoutTenancy(function () use ($tenant) {
            $totalArticles = Article::where('tenant_id', $tenant->id)->count();

            $positiveCount = Article::where('tenant_id', $tenant->id)->where('sentiment', 'positive')->count();
            $negativeCount = Article::where('tenant_id', $tenant->id)->where('sentiment', 'negative')->count();
            $neutralCount = Article::where('tenant_id', $tenant->id)->where('sentiment', 'neutral')->count();

            $activeKeywordsCount = Keyword::where('tenant_id', $tenant->id)->where('status', Keyword::STATUS_ACTIVE)->count();
            $accessibleSourcesCount = Source::accessible()->count();

            $unreadAlertsCount = Alert::where('tenant_id', $tenant->id)->where('status', Alert::STATUS_UNREAD)->count();

            return [
                'articles' => [
                    'total' => $totalArticles,
                    'positive' => $positiveCount,
                    'negative' => $negativeCount,
                    'neutral' => $neutralCount,
                ],
                'keywords' => [
                    'active_count' => $activeKeywordsCount,
                ],
                'sources' => [
                    'accessible_count' => $accessibleSourcesCount,
                ],
                'alerts' => [
                    'unread_count' => $unreadAlertsCount,
                ],
            ];
        });
    }
}
