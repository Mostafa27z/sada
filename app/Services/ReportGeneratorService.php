<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Collection;
use App\Models\Report;
use App\Support\TenantContext;

class ReportGeneratorService
{
    /**
     * Generate report file and return relative storage path.
     */
    public function generate(Report $report): string
    {
        $report->update(['status' => Report::STATUS_GENERATING]);

        $params = is_array($report->parameters) ? $report->parameters : (json_decode($report->parameters ?? '[]', true) ?: []);
        $scope = $params['scope'] ?? $report->type ?? 'all';
        $dateRange = $params['dateRange'] ?? '7days';

        // Format extension
        $fmt = strtolower($report->format ?: 'csv');
        $ext = ($fmt === 'excel' || $fmt === 'xlsx') ? 'csv' : $fmt;

        $filename = 'reports/report_' . $report->id . '_' . time() . '.' . $ext;
        $fullPath = storage_path('app/' . $filename);

        if (!file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        if ($ext === 'csv') {
            $this->generateCsvReport($report, $fullPath, $scope, $dateRange);
        } else {
            $this->generateJsonReport($report, $fullPath, $scope, $dateRange);
        }

        $report->update([
            'status' => Report::STATUS_COMPLETED,
            'file_path' => $filename,
        ]);

        return $filename;
    }

    protected function generateCsvReport(Report $report, string $fullPath, string $scope, string $dateRange): void
    {
        $handle = fopen($fullPath, 'w');
        // Add UTF-8 BOM for proper Arabic support in MS Excel
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $scopeLabel = match ($scope) {
            'campaigns' => 'تحليل ورصد الحملات والمواضيع',
            'sentiment' => 'تحليل المشاعر والانطباع العام',
            'trends' => 'رصد واكتشاف التريندات',
            default => 'جميع القنوات والصفحات',
        };

        $dateRangeLabel = match ($dateRange) {
            'today' => 'اليوم',
            'yesterday' => 'الأمس (آخر 24 ساعة)',
            '30days' => 'آخر 30 يوماً',
            default => 'آخر 7 أيام',
        };

        // Header Metadata
        fputcsv($handle, ['منصة صدى للرصد والتحليل الإعلامي - تقرير رسمي']);
        fputcsv($handle, ['اسم التقرير:', $report->name]);
        fputcsv($handle, ['نطاق التحليل:', $scopeLabel]);
        fputcsv($handle, ['الفترة الزمنية:', $dateRangeLabel]);
        fputcsv($handle, ['تاريخ ووقت الإصدار:', now()->toDateTimeString()]);
        fputcsv($handle, ['']);

        if ($scope === 'campaigns') {
            // Fetch tenant collections
            $collections = TenantContext::withoutTenancy(function () use ($report) {
                return Collection::with(['articles.comments'])
                    ->where('tenant_id', $report->tenant_id)
                    ->latest()
                    ->get();
            });

            fputcsv($handle, [
                '#',
                'اسم الحملة أو الرصد',
                'طريقة البحث',
                'الكلمات المفتاحية أو الرابط',
                'المنصات',
                'الدولة',
                'النتائج المسترجعة',
                'المشاعر الإيجابية',
                'المشاعر المحايدة',
                'المشاعر السلبية',
                'تاريخ الإنشاء',
                'الحالة'
            ]);

            $i = 1;
            foreach ($collections as $c) {
                $isKeyword = empty($c->link) || $c->link === '#' || !empty($c->keywords);
                $searchMethod = $isKeyword ? 'كلمة مفتاحية' : 'رابط مباشر';
                $targetTerm = !empty($c->keywords) ? $c->keywords : (!empty($c->link) && $c->link !== '#' ? $c->link : $c->name);
                $articles = $c->articles ?? collect();
                $allComments = $articles->flatMap(fn($a) => $a->comments ?? collect());

                $pos = $allComments->where('sentiment', 'positive')->count();
                $neu = $allComments->where('sentiment', 'neutral')->count();
                $neg = $allComments->where('sentiment', 'negative')->count();

                fputcsv($handle, [
                    $i++,
                    $c->name,
                    $searchMethod,
                    $targetTerm,
                    $c->platform ?: 'الكل',
                    $c->country ?: 'SA',
                    $articles->count() + $allComments->count(),
                    $pos,
                    $neu,
                    $neg,
                    $c->created_at?->format('Y-m-d H:i') ?? 'الآن',
                    $c->status,
                ]);
            }
        } else {
            // Fetch articles
            $articles = TenantContext::withoutTenancy(function () use ($report) {
                return Article::with('source')
                    ->where('tenant_id', $report->tenant_id)
                    ->latest()
                    ->take(500)
                    ->get();
            });

            fputcsv($handle, [
                '#',
                'عنوان المنشور أو الخبر',
                'المصدر أو الكاتب',
                'المنصة',
                'الانطباع والمشاعر',
                'تاريخ النشر',
                'الرابط',
            ]);

            $i = 1;
            foreach ($articles as $a) {
                $sentimentArabic = match ($a->sentiment) {
                    'positive' => 'إيجابي',
                    'negative' => 'سلبي',
                    default => 'محايد',
                };

                fputcsv($handle, [
                    $i++,
                    $a->title,
                    $a->author ?: $a->source?->name ?: 'مصدر إعلامي',
                    $a->platform ?: 'web',
                    $sentimentArabic,
                    $a->published_at?->format('Y-m-d H:i') ?? 'الآن',
                    $a->url,
                ]);
            }
        }

        fclose($handle);
    }

    protected function generateJsonReport(Report $report, string $fullPath, string $scope, string $dateRange): void
    {
        $articles = TenantContext::withoutTenancy(function () use ($report) {
            return Article::where('tenant_id', $report->tenant_id)->latest()->take(100)->get();
        });

        $data = [
            'report_id' => $report->id,
            'tenant_id' => $report->tenant_id,
            'name' => $report->name,
            'type' => $report->type,
            'scope' => $scope,
            'date_range' => $dateRange,
            'articles_count' => $articles->count(),
            'generated_at' => now()->toDateTimeString(),
            'articles' => $articles->map(fn ($a) => [
                'title' => $a->title,
                'sentiment' => $a->sentiment,
                'published_at' => $a->published_at?->toDateTimeString(),
            ])->toArray(),
        ];

        file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
