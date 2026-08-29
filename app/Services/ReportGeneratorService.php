<?php

namespace App\Services;

use App\Models\Article;
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

        $articles = TenantContext::withoutTenancy(function () use ($report) {
            return Article::where('tenant_id', $report->tenant_id)->latest()->take(100)->get();
        });

        $data = [
            'report_id' => $report->id,
            'tenant_id' => $report->tenant_id,
            'name' => $report->name,
            'type' => $report->type,
            'articles_count' => $articles->count(),
            'generated_at' => now()->toDateTimeString(),
            'articles' => $articles->map(fn ($a) => [
                'title' => $a->title,
                'sentiment' => $a->sentiment,
                'published_at' => $a->published_at?->toDateTimeString(),
            ])->toArray(),
        ];

        $filename = 'reports/report_' . $report->id . '_' . time() . '.' . $report->format;
        $fullPath = storage_path('app/' . $filename);

        if (!file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $report->update([
            'status' => Report::STATUS_COMPLETED,
            'file_path' => $filename,
        ]);

        return $filename;
    }
}
