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

            $query = $buildQuery($dateFrom, $dateTo);
            $total = (clone $query)->count();

            if ($total === 0 && ($dateFrom || $dateTo)) {
                $hasAny = Article::where('tenant_id', $tenant->id)->exists();
                if ($hasAny) {
                    $query = $buildQuery(null, null);
                    $total = (clone $query)->count();
                }
            }

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

            $query = $buildQuery($dateFrom, $dateTo);
            $count = (clone $query)->count();

            if ($count === 0 && ($dateFrom || $dateTo)) {
                $hasAny = Article::where('tenant_id', $tenant->id)->exists();
                if ($hasAny) {
                    $query = $buildQuery(null, null);
                }
            }

            $articles = $query->select(['id', 'tenant_id', 'sentiment', 'published_at', 'created_at'])->get();

            $grouped = $articles->groupBy(function ($article) {
                $dt = $article->published_at && $article->published_at->year >= 2000
                    ? $article->published_at
                    : $article->created_at;
                return $dt ? $dt->format('Y-m-d') : date('Y-m-d');
            })->sortKeys();

            $trends = [];
            foreach ($grouped as $date => $items) {
                $trends[] = [
                    'date' => (string) $date,
                    'total' => $items->count(),
                    'positive' => $items->where('sentiment', 'positive')->count(),
                    'negative' => $items->where('sentiment', 'negative')->count(),
                    'neutral' => $items->where('sentiment', 'neutral')->count(),
                ];
            }

            return $trends;
        });
    }

    /**
     * Get top mentioned keywords / topics with optional sentiment filtering.
     */
    public function getTopKeywords(Tenant $tenant, int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null, ?string $sentiment = null): array
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $limit, $dateFrom, $dateTo, $sentiment) {
            // Ensure any active collection topics are registered in keywords and article_keyword
            $collections = \App\Models\Collection::where('tenant_id', $tenant->id)->get();
            foreach ($collections as $c) {
                $rawName = trim(preg_replace('/^(رصد\s+)?كلمة:\s*"?([^"]+)"?/u', '$2', $c->name));
                $rawName = trim($rawName, '"\' ');
                if (empty($rawName)) continue;

                $kw = Keyword::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $rawName],
                    ['status' => 'active', 'category' => 'general']
                );

                $articleIds = $c->articles()->pluck('articles.id')->toArray();
                if (!empty($articleIds)) {
                    $kw->articles()->syncWithoutDetaching($articleIds);
                }
            }

            $buildQuery = function (?string $df, ?string $dt) use ($tenant, $sentiment, $limit) {
                return Keyword::where('tenant_id', $tenant->id)
                    ->withCount(['articles' => function ($q) use ($df, $dt, $sentiment) {
                        if ($df) {
                            $dfBounded = strlen($df) === 10 ? $df . ' 00:00:00' : $df;
                            $q->where('published_at', '>=', $dfBounded);
                        }
                        if ($dt) {
                            $dtBounded = strlen($dt) === 10 ? $dt . ' 23:59:59' : $dt;
                            $q->where('published_at', '<=', $dtBounded);
                        }
                        if ($sentiment) {
                            $q->where('sentiment', $sentiment);
                        }
                    }])
                    ->orderByDesc('articles_count')
                    ->limit($limit)
                    ->get();
            };

            $results = $buildQuery($dateFrom, $dateTo);

            // If empty or all zero and dates were requested, fallback to all dates
            $hasActivity = $results->contains(fn ($k) => $k->articles_count > 0);
            if (!$hasActivity && ($dateFrom || $dateTo)) {
                $results = $buildQuery(null, null);
            }

            return $results->map(fn ($k) => [
                'id' => $k->id,
                'name' => $k->name,
                'articles_count' => (int) $k->articles_count,
            ])->toArray();
        });
    }

    /**
     * Get top reporting platforms / sources for tenant articles.
     */
    public function getTopSources(Tenant $tenant, int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $limit, $dateFrom, $dateTo) {
            $buildQuery = function (?string $df, ?string $dt) use ($tenant) {
                $query = Article::where('tenant_id', $tenant->id);

                if ($df) {
                    $dfBounded = strlen($df) === 10 ? $df . ' 00:00:00' : $df;
                    $query->where('published_at', '>=', $dfBounded);
                }
                if ($dt) {
                    $dtBounded = strlen($dt) === 10 ? $dt . ' 23:59:59' : $dt;
                    $query->where('published_at', '<=', $dtBounded);
                }

                return $query->get(['id', 'url', 'raw_data']);
            };

            $articles = $buildQuery($dateFrom, $dateTo);

            if ($articles->isEmpty() && ($dateFrom || $dateTo)) {
                $articles = $buildQuery(null, null);
            }

            $counts = [];
            foreach ($articles as $a) {
                $p = strtolower($a->raw_data['platform'] ?? '');
                if (!$p) {
                    $u = strtolower($a->url ?? '');
                    if (str_contains($u, 'twitter') || str_contains($u, 'x.com')) {
                        $p = 'x';
                    } elseif (str_contains($u, 'facebook') || str_contains($u, 'fb.')) {
                        $p = 'facebook';
                    } elseif (str_contains($u, 'instagram')) {
                        $p = 'instagram';
                    } else {
                        $p = 'web';
                    }
                } elseif ($p === 'twitter' || $p === 'twitter (x)') {
                    $p = 'x';
                } elseif ($p === 'insta') {
                    $p = 'instagram';
                } elseif ($p === 'fb') {
                    $p = 'facebook';
                } elseif ($p === 'news') {
                    $p = 'web';
                }

                $counts[$p] = ($counts[$p] ?? 0) + 1;
            }

            arsort($counts);

            $names = [
                'x' => 'تويتر (X)',
                'facebook' => 'فيسبوك',
                'instagram' => 'إنستغرام',
                'web' => 'أخبار المواقع',
            ];

            $output = [];
            $idx = 1;
            foreach ($counts as $plat => $c) {
                $output[] = [
                    'id' => $idx++,
                    'name' => $names[$plat] ?? ucfirst($plat),
                    'type' => $plat === 'web' ? 'news' : $plat,
                    'articles_count' => (int) $c,
                ];
                if (count($output) >= $limit) break;
            }

            return $output;
        });
    }
}
