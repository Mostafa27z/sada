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
$countryCode = 'SA';
$dateFrom = '2026-09-16';
$dateTo = '2026-10-16';

// Test each call exactly as scrapeByKeywords does:
$gInsta = $method->invoke($service, $keywords, ['instagram'], $countryCode, $dateFrom, $dateTo, 5);
$instaPosts = $gInsta['posts_by_platform']['instagram_posts'] ?? [];
echo "Step 1: gInsta count: " . count($instaPosts) . "\n";

$gFb = $method->invoke($service, $keywords, ['facebook'], $countryCode, $dateFrom, $dateTo, 5);
$fbPosts = $gFb['posts_by_platform']['facebook_posts'] ?? [];
echo "Step 1: gFb count: " . count($fbPosts) . "\n";

$gX = $method->invoke($service, $keywords, ['x'], $countryCode, $dateFrom, $dateTo, 5);
$xPosts = $gX['posts_by_platform']['x_posts'] ?? [];
echo "Step 1: gX count: " . count($xPosts) . "\n";

$gTt = $method->invoke($service, $keywords, ['tiktok'], $countryCode, $dateFrom, $dateTo, 5);
$tiktokPosts = $gTt['posts_by_platform']['tiktok_posts'] ?? [];
echo "Step 1: gTt count: " . count($tiktokPosts) . "\n";

$gWeb = $method->invoke($service, $keywords, ['web'], $countryCode, $dateFrom, $dateTo, 5);
$webPosts = $gWeb['posts_by_platform']['web_posts'] ?? $gWeb['posts_by_platform']['other_posts'] ?? [];
echo "Step 1: gWeb count: " . count($webPosts) . "\n";

$filterRelevant = function(array $posts) use ($keywords) {
    return array_values(array_filter($posts, function($p) use ($keywords) {
        $txt = ($p['text'] ?? '') . ' ' . ($p['title'] ?? '') . ' ' . ($p['author'] ?? '');
        $rel = \App\Support\KeywordRelevanceFilter::isContentRelevant($txt, $keywords);
        return $rel;
    }));
};

echo "After filterRelevant:\n";
echo "  Insta: " . count($filterRelevant($instaPosts)) . "\n";
echo "  Fb: " . count($filterRelevant($fbPosts)) . "\n";
echo "  X: " . count($filterRelevant($xPosts)) . "\n";
echo "  Tt: " . count($filterRelevant($tiktokPosts)) . "\n";
echo "  Web: " . count($filterRelevant($webPosts)) . "\n";
