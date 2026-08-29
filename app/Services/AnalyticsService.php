<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Keyword;
use App\Models\Source;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * Get sentiment breakdown distribution.
     */
    public function getSentimentDistribution(Tenant $tenant, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $dateFrom, $dateTo) {
            $query = Article::where('tenant_id', $tenant->id);

            if ($dateFrom) {
                $query->where('published_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->where('published_at', '<=', $dateTo);
            }

            $total = (clone $query)->count();
            $positive = (clone $query)->where('sentiment', 'positive')->count();
            $negative = (clone $query)->where('sentiment', 'negative')->count();
            $neutral = (clone $query)->where('sentiment', 'neutral')->count();

            return [
                'total' => $total,
                'distribution' => [
                    'positive' => [
                        'count' => $positive,
                        'percentage' => $total > 0 ? round(($positive / $total) * 100, 2) : 0,
                    ],
                    'negative' => [
                        'count' => $negative,
                        'percentage' => $total > 0 ? round(($negative / $total) * 100, 2) : 0,
                    ],
                    'neutral' => [
                        'count' => $neutral,
                        'percentage' => $total > 0 ? round(($neutral / $total) * 100, 2) : 0,
                    ],
                ],
            ];
        });
    }

    /**
     * Get volume trends grouped by date.
     */
    public function getVolumeTrends(Tenant $tenant, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $dateFrom, $dateTo) {
            $query = Article::where('tenant_id', $tenant->id);

            if ($dateFrom) {
                $query->where('published_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->where('published_at', '<=', $dateTo);
            }

            $trends = $query->select(
                DB::raw('DATE(published_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as positive"),
                DB::raw("SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative"),
                DB::raw("SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as neutral")
            )
            ->groupBy(DB::raw('DATE(published_at)'))
            ->orderBy('date', 'asc')
            ->get();

            return $trends->toArray();
        });
    }

    /**
     * Get top mentioned keywords.
     */
    public function getTopKeywords(Tenant $tenant, int $limit = 10): array
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $limit) {
            return Keyword::where('tenant_id', $tenant->id)
                ->withCount('articles')
                ->orderBy('articles_count', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn ($k) => [
                    'id' => $k->id,
                    'name' => $k->name,
                    'articles_count' => $k->articles_count,
                ])
                ->toArray();
        });
    }

    /**
     * Get top reporting sources for tenant articles.
     */
    public function getTopSources(Tenant $tenant, int $limit = 10): array
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $limit) {
            $results = Article::where('articles.tenant_id', $tenant->id)
                ->whereNotNull('source_id')
                ->join('sources', 'articles.source_id', '=', 'sources.id')
                ->select('sources.id', 'sources.name', 'sources.type', DB::raw('COUNT(articles.id) as articles_count'))
                ->groupBy('sources.id', 'sources.name', 'sources.type')
                ->orderBy('articles_count', 'desc')
                ->limit($limit)
                ->get();

            return $results->toArray();
        });
    }
}
