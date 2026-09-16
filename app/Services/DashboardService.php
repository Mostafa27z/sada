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
    public function getStats(Tenant $tenant, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $dateFrom, $dateTo) {
            $articlesQuery = Article::where('tenant_id', $tenant->id);

            if ($dateFrom) {
                $dateFromBounded = strlen($dateFrom) === 10 ? $dateFrom . ' 00:00:00' : $dateFrom;
                $articlesQuery->where('published_at', '>=', $dateFromBounded);
            }
            if ($dateTo) {
                $dateToBounded = strlen($dateTo) === 10 ? $dateTo . ' 23:59:59' : $dateTo;
                $articlesQuery->where('published_at', '<=', $dateToBounded);
            }

            $totalArticles = (clone $articlesQuery)->count();
            $positiveCount = (clone $articlesQuery)->where('sentiment', 'positive')->count();
            $negativeCount = (clone $articlesQuery)->where('sentiment', 'negative')->count();
            $neutralCount = (clone $articlesQuery)->where('sentiment', 'neutral')->count();

            $platformCounts = ['x' => 0, 'web' => 0, 'instagram' => 0, 'facebook' => 0, 'tiktok' => 0];
            $articlesForPlatforms = (clone $articlesQuery)->with('source')->get(['id', 'tenant_id', 'source_id', 'raw_data']);
            foreach ($articlesForPlatforms as $art) {
                $p = strtolower($art->raw_data['platform'] ?? ($art->source?->type ?? 'web'));
                if (str_contains($p, 'x') || str_contains($p, 'twitter')) {
                    $platformCounts['x']++;
                } elseif (str_contains($p, 'insta')) {
                    $platformCounts['instagram']++;
                } elseif (str_contains($p, 'face')) {
                    $platformCounts['facebook']++;
                } elseif (str_contains($p, 'tik')) {
                    $platformCounts['tiktok']++;
                } else {
                    $platformCounts['web']++;
                }
            }

            $activeKeywordsCount = Keyword::where('tenant_id', $tenant->id)->where('status', Keyword::STATUS_ACTIVE)->count();
            $accessibleSourcesCount = Source::accessible()->count();

            $unreadAlertsCount = Alert::where('tenant_id', $tenant->id)->where('status', Alert::STATUS_UNREAD)->count();

            return [
                'articles' => [
                    'total' => $totalArticles,
                    'positive' => $positiveCount,
                    'negative' => $negativeCount,
                    'neutral' => $neutralCount,
                    'platforms' => $platformCounts,
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
