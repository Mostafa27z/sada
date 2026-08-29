<?php

namespace App\Integrations\Services;

use App\Integrations\Contracts\ExternalApiClientInterface;
use Illuminate\Support\Str;

class MockExternalApiClient implements ExternalApiClientInterface
{
    public function triggerScrape(array $sources, array $options = []): array
    {
        return [
            'status' => 'queued',
            'job_id' => 'scrape_' . Str::random(10),
            'sources_count' => count($sources),
        ];
    }

    public function analyzeSentiment(string $text): array
    {
        return [
            'sentiment' => 'positive',
            'sentiment_score' => 0.8500,
        ];
    }

    public function summarizeArticle(string $text): string
    {
        return 'ملخص ذكي تم إنشاؤه بواسطة الخدمة الخارجية للمقال الرصدي.';
    }

    public function extractEntities(string $text): array
    {
        return [
            'organizations' => ['وزارة الاتصالات', 'هيئة الحكومة الرقمية'],
            'locations' => ['الرياض', 'المملكة العربية السعودية'],
            'topics' => ['الذكاء الاصطناعي', 'التحول الرقمي'],
        ];
    }
}
