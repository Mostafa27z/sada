<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = app(App\Services\AiScraperService::class);

$keywords = ['متعب بن عبدالله الحربي', 'مجمع تبوك الطبي', 'مجمع تبوك الطبى'];
$platforms = ['x', 'facebook', 'instagram', 'tiktok', 'web'];
$dateFrom = '2026-09-16';
$dateTo = '2026-10-16';
$platformLimits = ['facebook' => 5, 'instagram' => 5, 'tiktok' => 5, 'web' => 5, 'x' => 5];

$res = $service->scrapeByKeywords(
    $keywords,
    $platforms,
    'SA',
    $dateFrom,
    $dateTo,
    25,
    null,
    $platformLimits
);

$posts = $res['posts_by_platform'] ?? [];
echo "FULL scrapeByKeywords RESULT:\n";
foreach ($posts as $k => $items) {
    echo "=== {$k} (" . count($items) . ") ===\n";
    foreach (array_slice($items, 0, 3) as $it) {
        echo "  [{$it['author']}] {$it['title']} ({$it['created_at']})\n";
    }
}
