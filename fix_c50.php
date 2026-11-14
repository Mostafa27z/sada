<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Comment;

$c = Comment::withoutGlobalScopes()->find(50);
if ($c) {
    $raw = is_array($c->raw_data) ? $c->raw_data : [];
    $raw['sentiment'] = 'positive';
    $raw['sentiment_reason'] = 'التعليق يبرر طبيعة عمل المنشأة ويوضح أن الدوام ينتهي عصراً، مما يدحض شكوى المشتكي ويدعم موقف الجهة.';

    $c->sentiment = 'positive';
    $c->sentiment_score = 0.90;
    $c->raw_data = $raw;
    $c->save();

    echo "Fixed Comment 50: sentiment={$c->sentiment}\n";
    echo "Reason: {$raw['sentiment_reason']}\n";
}
