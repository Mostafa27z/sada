<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$glCode = 'SA';
$ceid = 'SA:ar';
$streamCtx = stream_context_create([
    'http' => ['timeout' => 8, 'user_agent' => 'Mozilla/5.0'],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
]);

$now = time();
$dateFrom = '2026-09-16';
$dateTo = '2026-10-16';
$fromTs = strtotime($dateFrom . ' 00:00:00');
$toTs = strtotime($dateTo . ' 23:59:59');

$plats = ['facebook' => 'site:facebook.com ', 'instagram' => 'site:instagram.com ', 'x' => '(site:twitter.com OR site:x.com) '];

foreach ($plats as $name => $prefix) {
    $url = "https://news.google.com/rss/search?q=" . urlencode("{$prefix}\"مجمع تبوك الطبي\"") . "&hl=ar&gl={$glCode}&ceid={$ceid}";
    $content = @file_get_contents($url, false, $streamCtx);
    $xml = $content ? @simplexml_load_string($content) : null;
    $items = ($xml && isset($xml->channel->item)) ? $xml->channel->item : [];
    echo "=== {$name} (count: " . count($items) . ") ===\n";
    foreach ($items as $it) {
        $pubDate = (string)$it->pubDate;
        $ts = strtotime($pubDate);
        $dateStr = date('Y-m-d H:i:s', $ts);
        $inRange = ($fromTs && $ts < $fromTs) ? 'BEFORE date_from' : (($toTs && $ts > $toTs) ? 'AFTER date_to' : 'IN RANGE');
        echo "  [{$dateStr}] ({$inRange}) Title: " . (string)$it->title . "\n";
    }
}
