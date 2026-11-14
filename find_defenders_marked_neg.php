<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Comment;

$comments = Comment::withoutGlobalScopes()->get();
echo "Checking all " . count($comments) . " comments in DB...\n";

$diff = [];
foreach ($comments as $c) {
    $raw = is_array($c->raw_data) ? $c->raw_data : [];
    $rawSent = $raw['sentiment'] ?? null;
    $reason = $raw['sentiment_reason'] ?? '';
    
    // Check if reason says "يدعم موقف الجهة" or "يدافع عن الجهة" or "تكذيب لصاحب المنشور" or "دفاعاً عن"
    $isDefendingEntity = (
        str_contains($reason, 'يدعم موقف الجهة') ||
        str_contains($reason, 'دعم لموقف الجهة') ||
        str_contains($reason, 'دعماً لموقف الجهة') ||
        str_contains($reason, 'دفاعاً عن المنشأة') ||
        str_contains($reason, 'دفاع عن المنشأة') ||
        str_contains($reason, 'دفاع عن الجهة') ||
        str_contains($reason, 'يدافع عن الجهة') ||
        str_contains($reason, 'يدحض شكوى') ||
        str_contains($reason, 'تفنيد ادعاء') ||
        str_contains($reason, 'تكذيب صاحب') ||
        str_contains($reason, 'تكذيب صريح')
    );

    if ($isDefendingEntity && $c->sentiment === 'negative') {
        $diff[] = [
            'id' => $c->id,
            'author' => $c->author,
            'text' => $c->comment_text,
            'db_sent' => $c->sentiment,
            'reason' => $reason
        ];
    }
}

echo "Found " . count($diff) . " comments that DEFEND the entity but are set to negative:\n";
foreach ($diff as $d) {
    echo "- ID {$d['id']} | {$d['author']}: {$d['text']}\n  Reason: {$d['reason']}\n";
}
