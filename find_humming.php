<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Comment;

$c = Comment::withoutGlobalScopes()->where('author', 'LIKE', '%Hummingbird%')->first();
if ($c) {
    echo "ID: {$c->id} | Author: {$c->author} | Text: {$c->comment_text}\n";
    echo "Sentiment: {$c->sentiment}\n";
    $raw = is_array($c->raw_data) ? $c->raw_data : [];
    echo "Reason: " . ($raw['sentiment_reason'] ?? '') . "\n";
} else {
    echo "Not found\n";
}
