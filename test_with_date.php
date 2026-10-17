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
$dateFrom = '2026-09-16';
$dateTo = '2026-10-16';

$res = $method->invoke($service, $keywords, ['web', 'x', 'facebook', 'instagram', 'tiktok'], 'SA', $dateFrom, $dateTo, 5);

$posts = $res['posts_by_platform'] ?? [];
echo "WITH DATE FILTER ({$dateFrom} to {$dateTo}):\n";
foreach ($posts as $k => $items) {
    echo "=== {$k} (" . count($items) . ") ===\n";
    foreach (array_slice($items, 0, 3) as $it) {
        echo "  [{$it['author']}] {$it['title']} ({$it['created_at']})\n";
    }
}
