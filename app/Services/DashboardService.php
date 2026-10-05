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
            $buildQuery = function (?string $df, ?string $dt) use ($tenant) {
                $q = Article::where('tenant_id', $tenant->id);

                if ($df) {
                    $dfBounded = strlen($df) === 10 ? $df . ' 00:00:00' : $df;
                    $q->where(function ($sub) use ($dfBounded) {
                        $sub->where(function ($valid) use ($dfBounded) {
                            $valid->where('published_at', '>=', $dfBounded)
                                  ->where('published_at', '>=', '2000-01-01');
                        })->orWhere(function ($invalid) use ($dfBounded) {
                            $invalid->where(function ($subInv) {
                                $subInv->whereNull('published_at')
                                       ->orWhere('published_at', '<', '2000-01-01');
                            })->where('created_at', '>=', $dfBounded);
                        });
                    });
                }
                if ($dt) {
                    $dtBounded = strlen($dt) === 10 ? $dt . ' 23:59:59' : $dt;
                    $q->where(function ($sub) use ($dtBounded) {
                        $sub->where(function ($valid) use ($dtBounded) {
                            $valid->where('published_at', '<=', $dtBounded)
                                  ->where('published_at', '>=', '2000-01-01');
                        })->orWhere(function ($invalid) use ($dtBounded) {
                            $invalid->where(function ($subInv) {
                                $subInv->whereNull('published_at')
                                       ->orWhere('published_at', '<', '2000-01-01');
                            })->where('created_at', '<=', $dtBounded);
                        });
                    });
                }

                return $q;
            };

            $articlesQuery = $buildQuery($dateFrom, $dateTo);
            $totalArticles = (clone $articlesQuery)->count();

            if ($totalArticles === 0 && ($dateFrom || $dateTo)) {
                $hasAny = Article::where('tenant_id', $tenant->id)->exists();
                if ($hasAny) {
                    $articlesQuery = $buildQuery(null, null);
                    $totalArticles = (clone $articlesQuery)->count();
                }
            }
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
            $accessibleSourcesCount = Source::where(function ($q) use ($tenant) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id);
            })->count();

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
