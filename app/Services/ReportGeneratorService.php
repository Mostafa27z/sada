<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Collection;
use App\Models\Report;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReportGeneratorService
{
    public function __construct(protected GeminiAnalyticsService $gemini)
    {
    }

    /**
     * Generate report file (PDF-ready standalone document) and return storage path.
     */
    public function generate(Report $report): string
    {
        $report->update([
            'status' => Report::STATUS_GENERATING,
            'format' => 'pdf',
        ]);

        $tenant = TenantContext::withoutTenancy(function () use ($report) {
            return Tenant::find($report->tenant_id);
        });

        if ($tenant) {
            TenantContext::setTenant($tenant);
        }

        $companyName = $tenant?->name ?? 'منصة مرآة';
        $companyLogo = $tenant?->logo;
        $primaryColor = $tenant?->settings['theme']['primary_color'] ?? '#2563EB';
        $secondaryColor = $tenant?->settings['theme']['secondary_color'] ?? '#16A34A';

        $params = is_array($report->parameters) ? $report->parameters : (json_decode($report->parameters ?? '[]', true) ?: []);
        $scope = $params['scope'] ?? $report->type ?? 'all';
        $dateRange = $params['dateRange'] ?? '7days';

        // 1. Fetch relevant articles/collections
        $collectionId = $params['collection_id'] ?? null;
        $comments = collect();

        if ($collectionId && $collectionId !== 'all') {
            // Specific Campaign requested
            $collection = Collection::withoutGlobalScopes()->with(['articles.comments'])->find($collectionId);
            $articles = $collection
                ? $collection->articles()->withoutGlobalScopes()->with('comments')->latest()->limit(100)->get()
                : collect();

            // Strict comments: ONLY from this collection's articles (never fall back to unrelated tenant comments)
            $comments = $articles->flatMap(fn($a) => $a->comments)->values();

            $params['articles'] = $articles->map(function ($a) use ($collection) {
                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'content' => $a->content,
                    'author' => $a->author,
                    'source' => ['name' => $a->source?->name ?? $a->author ?? $collection->name ?? 'حملة مستهدفة'],
                    'campaign_name' => $collection->name,
                    'platform' => $a->platform ?? 'web',
                    'sentiment' => $a->sentiment ?? 'neutral',
                    'published_at' => $a->published_at ?? $a->created_at,
                    'url' => $a->url,
                ];
            })->values()->all();
        } elseif ($scope === 'campaigns' || $scope === 'campaign' || $report->type === 'campaign') {
            // ALL Campaigns requested: gather all tenant collections and their articles
            $allCols = Collection::withoutGlobalScopes()
                ->where('tenant_id', $report->tenant_id)
                ->with(['articles.comments'])
                ->latest()
                ->get();

            $collectedArticles = collect();
            foreach ($allCols as $col) {
                foreach ($col->articles as $art) {
                    $art->campaign_name = $col->name;
                    $collectedArticles->push($art);
                }
            }

            $articles = $collectedArticles->unique('id')->values();
            $comments = $articles->flatMap(fn($a) => $a->comments)->unique('id')->values();

            $params['campaigns_summary'] = $allCols->map(function ($c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'count' => $c->articles->count(),
                    'created_at' => (string) $c->created_at,
                ];
            })->values()->all();

            $params['articles'] = $articles->map(function ($a) {
                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'content' => $a->content,
                    'author' => $a->author,
                    'source' => ['name' => $a->campaign_name ?? $a->source?->name ?? $a->author ?? 'حملة مرصودة'],
                    'campaign_name' => $a->campaign_name ?? '',
                    'platform' => $a->platform ?? 'web',
                    'sentiment' => $a->sentiment ?? 'neutral',
                    'published_at' => $a->published_at ?? $a->created_at,
                    'url' => $a->url,
                ];
            })->values()->all();
        } elseif ($scope === 'radar' || $scope === 'industry_news' || $report->type === 'radar') {
            $newsQuery = \App\Models\TenantIndustryNews::query()->where('tenant_id', $report->tenant_id);
            if (!empty($params['batch_date']) && $params['batch_date'] !== 'all') {
                $newsQuery->where('batch_date', $params['batch_date']);
            }
            $rawNews = $newsQuery->latest('batch_date')->latest('created_at')->limit(50)->get();
            $articles = $rawNews->map(function ($item) {
                return (object) [
                    'id' => $item->id,
                    'title' => $item->title,
                    'content' => $item->summary ?? $item->title,
                    'summary' => $item->summary ?? $item->title,
                    'source' => (object) ['name' => $item->source_name ?? 'رادار الأخبار'],
                    'author' => $item->source_name ?? 'رادار الأخبار',
                    'platform' => 'web',
                    'sentiment' => 'positive',
                    'url' => $item->source_url ?? '',
                    'created_at' => $item->created_at ?? ($item->batch_date ? \Carbon\Carbon::parse($item->batch_date) : now()),
                    'published_at' => $item->published_at ?? $item->batch_date,
                    'comments' => collect(),
                ];
            });

            // Save radar news snapshot directly into parameters for API & PDF
            $params['radar_news'] = $rawNews->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'content' => $item->summary ?? $item->title,
                    'summary' => $item->summary ?? $item->title,
                    'source_name' => $item->source_name ?? 'رادار الأخبار',
                    'author' => $item->source_name ?? 'رادار الأخبار',
                    'url' => $item->source_url ?? '',
                    'platform' => 'web',
                    'sentiment' => 'positive',
                    'published_at' => $item->published_at ?? $item->batch_date,
                    'batch_date' => $item->batch_date,
                    'created_at' => (string) $item->created_at,
                    'category' => $item->category,
                ];
            })->values()->all();
        } elseif ($scope === 'complaints' || $report->type === 'complaints') {
            $compQuery = \App\Models\Complaint::query()->where('tenant_id', $report->tenant_id);
            if (!empty($params['batch_date']) && $params['batch_date'] !== 'all') {
                $compQuery->whereDate('created_at', $params['batch_date']);
            }
            $rawComps = $compQuery->latest()->limit(50)->get();
            $articles = $rawComps->map(function ($c) {
                return (object) [
                    'id' => $c->id,
                    'title' => 'بلاغ/شكوى: ' . ($c->customer_name ?? 'مستفيد') . ' - ' . ($c->subject ?? $c->title ?? 'شكوى واردة'),
                    'content' => $c->description ?? $c->content,
                    'summary' => $c->description ?? $c->content,
                    'source' => (object) ['name' => 'بوابة الشكاوى وبلاغات المستفيدين'],
                    'author' => $c->customer_name ?? 'مستفيد',
                    'platform' => 'crm',
                    'sentiment' => $c->status === 'resolved' ? 'positive' : ($c->priority === 'urgent' ? 'negative' : 'neutral'),
                    'url' => '',
                    'created_at' => $c->created_at,
                    'published_at' => $c->created_at,
                    'comments' => collect(),
                ];
            });

            // Save complaints snapshot directly into parameters for API & PDF
            $params['complaints'] = $rawComps->map(function ($c) {
                return [
                    'id' => $c->id,
                    'title' => 'بلاغ/شكوى: ' . ($c->customer_name ?? 'مستفيد') . ' - ' . ($c->subject ?? $c->title ?? 'شكوى واردة'),
                    'description' => $c->description ?? $c->content,
                    'content' => $c->description ?? $c->content,
                    'customer_name' => $c->customer_name ?? 'مستفيد',
                    'status' => $c->status,
                    'priority' => $c->priority,
                    'created_at' => (string) $c->created_at,
                ];
            })->values()->all();
        } else {
            $articlesQuery = Article::query()->where('tenant_id', $report->tenant_id);
            if ($dateRange === 'today') {
                $articlesQuery->whereDate('created_at', now()->today());
            } elseif ($dateRange === 'yesterday') {
                $articlesQuery->whereDate('created_at', now()->subDay()->toDateString());
            } elseif ($dateRange === '7days') {
                $articlesQuery->where('created_at', '>=', now()->subDays(7));
            } elseif ($dateRange === '20days') {
                $articlesQuery->where('created_at', '>=', now()->subDays(20));
            } elseif ($dateRange === '30days') {
                $articlesQuery->where('created_at', '>=', now()->subDays(30));
            } else {
                $articlesQuery->where('created_at', '>=', now()->subDays(30));
            }
            $articles = $articlesQuery->with('comments')->latest()->limit(50)->get();

            if ($articles->isEmpty()) {
                $articles = Article::query()->where('tenant_id', $report->tenant_id)->with('comments')->latest()->limit(50)->get();
            }

            $params['articles'] = $articles->map(function ($a) {
                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'content' => $a->content,
                    'author' => $a->author,
                    'source' => ['name' => $a->source?->name ?? $a->author ?? 'مصدر إعلامي'],
                    'platform' => $a->platform ?? 'web',
                    'sentiment' => $a->sentiment ?? 'neutral',
                    'published_at' => $a->published_at ?? $a->created_at,
                    'url' => $a->url,
                ];
            })->values()->all();

            $comments = $articles->flatMap(fn($a) => $a->comments)->values();
        }

        // Save comments snapshot
        $params['comments'] = $comments->map(function ($c) {
            return [
                'id' => $c->id,
                'author' => $c->author ?? $c->author_name ?? 'متابع',
                'comment_text' => $c->comment_text ?? $c->content ?? '',
                'sentiment' => $c->sentiment ?? 'neutral',
                'likes_count' => $c->likes_count ?? 0,
                'created_at' => (string) $c->created_at,
            ];
        })->values()->all();

        // 2. Metrics calculation
        $total = $articles->count();
        $pos = $articles->where('sentiment', 'positive')->count();
        $neu = $articles->where('sentiment', 'neutral')->count();
        $neg = $articles->where('sentiment', 'negative')->count();
        $commentsCount = $comments->count();

        $stats = [
            'total' => $total,
            'positive' => $pos,
            'neutral' => $neu,
            'negative' => $neg,
            'comments' => $commentsCount,
        ];

        // 3. AI Executive Summary (if not already cached in parameters)
        $aiSummary = $params['ai_summary'] ?? null;
        if (!$aiSummary && ($total > 0 || $commentsCount > 0)) {
            $sampleTexts = $articles->pluck('content')->filter()->values()->all();
            if (empty($sampleTexts) && $comments->isNotEmpty()) {
                $sampleTexts = $comments->pluck('comment_text')->filter()->values()->take(20)->all();
            }
            $aiData = $this->gemini->generateReportExecutiveSummary($companyName, $sampleTexts, $stats);
            $aiSummary = $aiData['summary'] ?? null;
            $params['ai_summary'] = $aiSummary;
            $params['positive_insights'] = $aiData['positive_insights'] ?? [];
            $params['negative_concerns'] = $aiData['negative_concerns'] ?? [];
            $params['recommendations'] = $aiData['recommendations'] ?? [];
        }

        // Attach snapshot of branding & stats to report parameters
        $params['stats'] = $stats;
        $params['theme_snapshot'] = [
            'company_name' => $companyName,
            'logo' => $companyLogo,
            'primary_color' => $primaryColor,
            'secondary_color' => $secondaryColor,
        ];

        // 4. Build Standalone Print-Perfect HTML Document (PDF Engine)
        $html = $this->buildPdfHtml([
            'report' => $report,
            'companyName' => $companyName,
            'companyLogo' => $companyLogo,
            'primaryColor' => $primaryColor,
            'secondaryColor' => $secondaryColor,
            'stats' => $stats,
            'aiSummary' => $aiSummary,
            'positiveInsights' => $params['positive_insights'] ?? [],
            'negativeConcerns' => $params['negative_concerns'] ?? [],
            'recommendations' => $params['recommendations'] ?? [],
            'articles' => $articles,
            'comments' => $comments,
        ]);

        $filename = 'reports/report_' . $report->id . '_' . time() . '.html';
        Storage::disk('public')->put($filename, $html);

        $report->update([
            'status' => Report::STATUS_COMPLETED,
            'format' => 'pdf',
            'file_path' => $filename,
            'parameters' => $params,
        ]);

        return $filename;
    }

    /**
     * Automatically generate a PDF report after a campaign/collection crawl completes.
     */
    public function generateCampaignReport(Collection $collection): ?Report
    {
        return TenantContext::withoutTenancy(function () use ($collection) {
            $articlesCount = $collection->articles()->count();
            if ($articlesCount === 0) {
                return null;
            }

            $report = Report::create([
                'tenant_id' => $collection->tenant_id,
                'name' => 'تقرير رصد حملة: ' . $collection->name,
                'type' => Report::TYPE_CAMPAIGN_AUTO,
                'format' => 'pdf',
                'status' => Report::STATUS_PENDING,
                'parameters' => [
                    'collection_id' => $collection->id,
                    'collection_name' => $collection->name,
                    'scope' => 'campaigns',
                    'dateRange' => 'today',
                ],
            ]);

            $this->generate($report);
            return $report->fresh();
        });
    }

    /**
     * Generate Daily AI Summary PDF report for a given tenant.
     */
    public function generateDailySummary(Tenant $tenant): ?Report
    {
        return TenantContext::withoutTenancy(function () use ($tenant) {
            $today = now()->format('Y-m-d');
            $report = Report::create([
                'tenant_id' => $tenant->id,
                'name' => 'التقرير اليومي الذكي - ' . $today,
                'type' => Report::TYPE_DAILY_SUMMARY,
                'format' => 'pdf',
                'status' => Report::STATUS_PENDING,
                'parameters' => [
                    'date' => $today,
                    'scope' => 'all',
                    'dateRange' => 'today',
                ],
            ]);

            $this->generate($report);
            return $report->fresh();
        });
    }

    /**
     * Generate News Radar PDF report for a given tenant and date.
     */
    public function generateRadarReport(Tenant $tenant, ?string $batchDate = null): ?Report
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $batchDate) {
            $date = $batchDate ? \Carbon\Carbon::parse($batchDate)->format('Y-m-d') : now()->format('Y-m-d');
            $report = Report::where('tenant_id', $tenant->id)
                ->where('type', 'radar')
                ->where(function ($q) use ($date) {
                    $q->where('parameters->batch_date', $date)
                      ->orWhere('name', 'like', "%{$date}%");
                })
                ->first();

            if (!$report) {
                $report = Report::create([
                    'tenant_id' => $tenant->id,
                    'name' => 'تقرير رادار الأخبار اليومي - ' . $date,
                    'type' => 'radar',
                    'format' => 'pdf',
                    'status' => Report::STATUS_PENDING,
                    'parameters' => [
                        'scope' => 'radar',
                        'batch_date' => $date,
                        'dateRange' => $date,
                    ],
                ]);
            } else {
                $params = is_array($report->parameters) ? $report->parameters : (json_decode($report->parameters ?? '[]', true) ?: []);
                $params['scope'] = 'radar';
                $params['batch_date'] = $date;
                $params['dateRange'] = $date;
                $report->update(['parameters' => $params]);
            }

            $this->generate($report);
            return $report->fresh();
        });
    }

    /**
     * Generate Complaints PDF report for a given tenant.
     */
    public function generateComplaintsReport(Tenant $tenant, ?string $dateRange = 'today'): ?Report
    {
        return TenantContext::withoutTenancy(function () use ($tenant, $dateRange) {
            $today = now()->format('Y-m-d');
            $report = Report::where('tenant_id', $tenant->id)
                ->where('type', 'complaints')
                ->where(function ($q) use ($today) {
                    $q->where('name', 'like', "%{$today}%")
                      ->orWhereDate('created_at', now()->today());
                })
                ->first();

            if (!$report) {
                $report = Report::create([
                    'tenant_id' => $tenant->id,
                    'name' => 'تقرير الشكاوى وبلاغات المستفيدين - ' . $today,
                    'type' => 'complaints',
                    'format' => 'pdf',
                    'status' => Report::STATUS_PENDING,
                    'parameters' => [
                        'scope' => 'complaints',
                        'dateRange' => $dateRange,
                    ],
                ]);
            }

            $this->generate($report);
            return $report->fresh();
        });
    }

    /**
     * Generate HTML document template styled with tenant's theme & branding.
     */
    protected function buildPdfHtml(array $data): string
    {
        $report = $data['report'];
        $companyName = htmlspecialchars($data['companyName'], ENT_QUOTES, 'UTF-8');
        $companyLogo = $data['companyLogo'];
        $primary = $data['primaryColor'];
        $secondary = $data['secondaryColor'];
        $stats = $data['stats'];
        $aiSummary = htmlspecialchars($data['aiSummary'] ?? 'تقرير الرصد والتحليل الإعلامي الشامل.', ENT_QUOTES, 'UTF-8');
        $positiveInsights = $data['positiveInsights'] ?? [];
        $negativeConcerns = $data['negativeConcerns'] ?? [];
        $recommendations = $data['recommendations'] ?? [];
        $articles = $data['articles'] ?? [];

        $posPct = $stats['total'] > 0 ? round(($stats['positive'] / $stats['total']) * 100) : 0;
        $neuPct = $stats['total'] > 0 ? round(($stats['neutral'] / $stats['total']) * 100) : 0;
        $negPct = $stats['total'] > 0 ? round(($stats['negative'] / $stats['total']) * 100) : 0;

        $logoHtml = $companyLogo
            ? "<img src='{$companyLogo}' alt='{$companyName}' style='max-height: 52px; max-width: 140px; object-fit: contain;' />"
            : "<div style='font-size: 20px; font-weight: 900; color: {$primary};'>{$companyName}</div>";

        $watermarkImage = $companyLogo
            ? "<img src='{$companyLogo}' alt='{$companyName}' style='max-width: 340px; max-height: 240px; object-fit: contain;' />"
            : "<div style='font-size: 38px; font-weight: 900; color: {$primary}; letter-spacing: 2px;'>{$companyName}</div>";


        $insightsHtml = '';
        foreach ($positiveInsights as $item) {
            $insightsHtml .= "<li style='margin-bottom: 4px; color: #166534;'>• " . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . "</li>";
        }

        $concernsHtml = '';
        foreach ($negativeConcerns as $item) {
            $concernsHtml .= "<li style='margin-bottom: 4px; color: #991b1b;'>• " . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . "</li>";
        }

        $recsHtml = '';
        foreach ($recommendations as $item) {
            $recsHtml .= "<li style='margin-bottom: 4px; color: #1e40af;'>• " . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . "</li>";
        }

        $tableRows = '';
        foreach ($articles as $index => $article) {
            $num = $index + 1;
            $platform = htmlspecialchars($article->platform ?? 'عام', ENT_QUOTES, 'UTF-8');
            $author = htmlspecialchars($article->author ?? 'مجهول', ENT_QUOTES, 'UTF-8');
            $snippet = htmlspecialchars(Str::limit($article->content ?? $article->title ?? '—', 85), ENT_QUOTES, 'UTF-8');
            $sentiment = $article->sentiment;
            $sColor = match ($sentiment) {
                'positive' => '#15803d',
                'negative' => '#b91c1c',
                default => '#4b5563',
            };
            $sBg = match ($sentiment) {
                'positive' => '#f0fdf4',
                'negative' => '#fef2f2',
                default => '#f9fafb',
            };
            $sLabel = match ($sentiment) {
                'positive' => 'إيجابي',
                'negative' => 'سلبي',
                default => 'محايد',
            };
            $date = $article->created_at ? $article->created_at->format('Y-m-d') : '—';

            $tableRows .= "
                <tr style='border-bottom: 1px solid #e5e7eb;'>
                    <td style='padding: 8px 10px; font-weight: bold; color: #6b7280; text-align: center;'>{$num}</td>
                    <td style='padding: 8px 10px; font-weight: bold; color: #111827;'>{$platform}</td>
                    <td style='padding: 8px 10px; color: #4b5563;'>{$author}</td>
                    <td style='padding: 8px 10px; color: #374151; font-size: 11px;'>{$snippet}</td>
                    <td style='padding: 8px 10px; text-align: center;'>
                        <span style='background: {$sBg}; color: {$sColor}; padding: 3px 8px; border-radius: 9999px; font-size: 10px; font-weight: bold;'>{$sLabel}</span>
                    </td>
                    <td style='padding: 8px 10px; color: #6b7280; font-size: 11px; text-align: center;'>{$date}</td>
                </tr>
            ";
        }

        $comments = $data['comments'] ?? collect();
        $commentRows = '';
        foreach ($comments->take(40) as $cIndex => $comment) {
            $cNum = $cIndex + 1;
            $cAuthor = htmlspecialchars($comment->author ?? 'متابع', ENT_QUOTES, 'UTF-8');
            $cText = htmlspecialchars(Str::limit($comment->comment_text ?? '—', 120), ENT_QUOTES, 'UTF-8');
            $cSentiment = $comment->sentiment ?? 'neutral';
            $csColor = match ($cSentiment) {
                'positive' => '#15803d',
                'negative' => '#b91c1c',
                default => '#4b5563',
            };
            $csBg = match ($cSentiment) {
                'positive' => '#f0fdf4',
                'negative' => '#fef2f2',
                default => '#f9fafb',
            };
            $csLabel = match ($cSentiment) {
                'positive' => 'إيجابي',
                'negative' => 'سلبي',
                default => 'محايد',
            };
            $cLikes = $comment->likes_count ?? 0;

            $commentRows .= "
                <tr style='border-bottom: 1px solid #e5e7eb;'>
                    <td style='padding: 8px 10px; font-weight: bold; color: #6b7280; text-align: center;'>{$cNum}</td>
                    <td style='padding: 8px 10px; font-weight: bold; color: #1e40af;'>{$cAuthor}</td>
                    <td style='padding: 8px 10px; color: #374151; font-size: 11px;'>{$cText}</td>
                    <td style='padding: 8px 10px; text-align: center;'>
                        <span style='background: {$csBg}; color: {$csColor}; padding: 3px 8px; border-radius: 9999px; font-size: 10px; font-weight: bold;'>{$csLabel}</span>
                    </td>
                    <td style='padding: 8px 10px; color: #6b7280; font-size: 11px; text-align: center;'>{$cLikes} إعجاب</td>
                </tr>
            ";
        }

        $commentsSection = '';
        if ($comments->isNotEmpty()) {
            $commentsSection = "
            <div style='margin-top: 24px;'>
                <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;'>
                    <h3 style='margin: 0; font-size: 13px; font-weight: 900; color: #111827;'>تحليل تفاعلات وتعليقات الجمهور المباشرة (Social Comments & Discussion)</h3>
                    <span style='font-size: 10px; color: #6b7280;'>إجمالي التعليقات المرصودة: " . $comments->count() . "</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th style='width: 35px;'>#</th>
                            <th style='width: 120px;'>المعلق</th>
                            <th>نص التعليق / التفاعل المرصود</th>
                            <th style='width: 75px; text-align: center;'>المشاعر</th>
                            <th style='width: 85px; text-align: center;'>التفاعل</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$commentRows}
                    </tbody>
                </table>
            </div>
            ";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>{$report->name} - {$companyName}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4;
            margin: 12mm 15mm;
        }
        body {
            font-family: 'Cairo', system-ui, -apple-system, sans-serif;
            background: #ffffff;
            color: #1f2937;
            margin: 0;
            padding: 24px;
            direction: rtl;
            font-size: 12px;
            line-height: 1.5;
        }
        .header-bar {
            height: 4px;
            background: linear-gradient(90deg, {$secondary}, {$primary});
            border-radius: 2px;
            margin-bottom: 20px;
        }
        .kpi-card {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px 16px;
            text-align: right;
        }
        .ai-box {
            background: #ffffff;
            border: 1.5px solid {$secondary};
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            border-radius: 14px;
            padding: 16px 20px;
            margin: 20px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 11px;
        }
        th {
            background: {$primary};
            color: #ffffff;
            padding: 10px;
            text-align: right;
            font-weight: 800;
        }
        th:first-child { border-top-right-radius: 8px; text-align: center; }
        th:last-child { border-top-left-radius: 8px; text-align: center; }
        .pdf-watermark {
            position: fixed;
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-25deg);
            opacity: 0.05;
            z-index: -9999;
            pointer-events: none;
            text-align: center;
            width: 100%;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="pdf-watermark">
        {$watermarkImage}
    </div>
    <div class="header-bar"></div>

    <!-- Document Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 16px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            {$logoHtml}
            <div>
                <h1 style="margin: 0; font-size: 18px; font-weight: 900; color: #111827;">{$report->name}</h1>
                <p style="margin: 2px 0 0 0; font-size: 11px; color: #6b7280; font-weight: 600;">{$companyName} • وثيقة رسمية معتمدة</p>
            </div>
        </div>
        <div style="text-align: left; font-size: 10px; color: #6b7280; direction: ltr;">
            <div style="font-weight: bold; color: {$primary};">REF: MIRAA-REP-{$report->id}</div>
            <div>" . now()->format('Y-m-d H:i') . "</div>
        </div>
    </div>

    <!-- AI Executive Summary Card -->
    <div class="ai-box">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="background: {$secondary}; color: #ffffff; font-size: 10px; font-weight: 900; padding: 2px 8px; border-radius: 6px;">AI Summary</span>
                <span style="font-size: 13px; font-weight: 900; color: #111827;">الملخص التنفيذي الذكي لحالة الرصد</span>
            </div>
            <span style="font-size: 10px; font-weight: bold; color: {$primary};">تحليل فوري دقيق</span>
        </div>
        <p style="margin: 0 0 12px 0; font-size: 12px; color: #374151; line-height: 1.6;">
            {$aiSummary}
        </p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px; font-size: 11px;">
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px;">
                <div style="font-weight: 800; color: #166534; margin-bottom: 4px;">أبرز الإيجابيات المرصودة:</div>
                <ul style="margin: 0; padding-right: 14px; list-style: none;">
                    {$insightsHtml}
                </ul>
            </div>
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px;">
                <div style="font-weight: 800; color: #991b1b; margin-bottom: 4px;">ملاحظات ونقاط الاهتمام:</div>
                <ul style="margin: 0; padding-right: 14px; list-style: none;">
                    {$concernsHtml}
                </ul>
            </div>
        </div>
    </div>

    <!-- KPI Statistics Grid -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px;">
        <div class="kpi-card">
            <div style="font-size: 10px; color: #6b7280; font-weight: bold;">إجمالي المنشورات المرصودة</div>
            <div style="font-size: 20px; font-weight: 900; color: {$primary}; margin-top: 4px;">{$stats['total']}</div>
        </div>
        <div class="kpi-card">
            <div style="font-size: 10px; color: #6b7280; font-weight: bold;">المشاعر الإيجابية</div>
            <div style="font-size: 20px; font-weight: 900; color: #16a34a; margin-top: 4px;">{$posPct}% <span style="font-size: 11px; font-weight: normal; color: #6b7280;">({$stats['positive']})</span></div>
        </div>
        <div class="kpi-card">
            <div style="font-size: 10px; color: #6b7280; font-weight: bold;">المشاعر المحايدة</div>
            <div style="font-size: 20px; font-weight: 900; color: #4b5563; margin-top: 4px;">{$neuPct}% <span style="font-size: 11px; font-weight: normal; color: #6b7280;">({$stats['neutral']})</span></div>
        </div>
        <div class="kpi-card">
            <div style="font-size: 10px; color: #6b7280; font-weight: bold;">المشاعر السلبية</div>
            <div style="font-size: 20px; font-weight: 900; color: #dc2626; margin-top: 4px;">{$negPct}% <span style="font-size: 11px; font-weight: normal; color: #6b7280;">({$stats['negative']})</span></div>
        </div>
    </div>

    <!-- Monitored Posts Table -->
    <div style="margin-top: 15px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
            <h3 style="margin: 0; font-size: 13px; font-weight: 900; color: #111827;">سجل المنشورات والرصد التفصيلي</h3>
            <span style="font-size: 10px; color: #6b7280;">آخر 50 منشور مرصود</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width: 35px;">#</th>
                    <th style="width: 80px;">المنصة</th>
                    <th style="width: 110px;">المؤلف</th>
                    <th>محتوى المنشور أو التعليق</th>
                    <th style="width: 75px; text-align: center;">المشاعر</th>
                    <th style="width: 85px; text-align: center;">التاريخ</th>
                </tr>
            </thead>
            <tbody>
                {$tableRows}
            </tbody>
        </table>
    </div>

    {$commentsSection}

    <!-- Official Document Footer -->
    <div style="border-top: 1px solid #e5e7eb; padding-top: 14px; margin-top: 24px; display: flex; justify-content: space-between; align-items: center; font-size: 10px; color: #9ca3af;">
        <div>تم توليد هذا التقرير تلقائياً بواسطة نظام التحليل الذكي لمنصة مرآة © " . date('Y') . "</div>
        <div style="font-weight: bold; color: {$primary};">منصة مرآة للرصد والاستشعار الإعلامي</div>
    </div>
</body>
</html>
HTML;
    }
}
