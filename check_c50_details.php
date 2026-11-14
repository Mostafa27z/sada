<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Comment;

$c = Comment::withoutGlobalScopes()->find(50);
echo "c->sentiment: " . $c->sentiment . "\n";
echo "c->sentiment_score: " . $c->sentiment_score . "\n";
echo "c->raw_data: " . json_encode($c->raw_data, JSON_UNESCAPED_UNICODE) . "\n";
