<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = app(App\Services\AiScraperService::class);
$reflection = new ReflectionClass($service);
$method = $reflection->getMethod('scrapeKeywordsViaGoogleNews');
$method->setAccessible(true);

$keywords = ['متعب بن عبدالله الحربي', 'مجمع تبوك الطبي', 'مجمع تبوك الطبى'];
$gTt = $method->invoke($service, $keywords, ['tiktok'], 'SA', '2026-09-16', '2026-10-16', 5);
$tiktokPosts = $gTt['posts_by_platform']['tiktok_posts'] ?? [];

echo "Found " . count($tiktokPosts) . " tiktok posts\n";

$geminiService = app(App\Services\GeminiAnalyticsService::class);

try {
    $sentimentMap = $geminiService->classifyPostsSentiment($tiktokPosts, null, $keywords);
    echo "SentimentMap count: " . count($sentimentMap) . "\n";
    foreach ($sentimentMap as $k => $v) {
        echo "  Key: {$k} => is_relevant: " . var_export($v['is_relevant'] ?? null, true) . ", reason: " . ($v['reason'] ?? 'none') . "\n";
    }
} catch (\Throwable $e) {
    echo "Gemini ERROR: " . $e->getMessage() . "\n";
}
