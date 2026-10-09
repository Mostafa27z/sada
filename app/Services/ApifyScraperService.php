<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApifyScraperService
{
    protected string $token;
    protected string $baseUrl = 'https://api.apify.com/v2';

    public function __construct()
    {
        $this->token = config('services.apify.token') ?: env('APIFY_API_TOKEN') ?: env('APIFY_TOKEN') ?: env('APIFY_TOKEN_2') ?: '';
    }

    protected static ?int $circuitBrokenUntil = null;

    public static function isCircuitOpen(): bool
    {
        return self::$circuitBrokenUntil !== null && time() < self::$circuitBrokenUntil;
    }

    public static function resetCircuit(): void
    {
        self::$circuitBrokenUntil = null;
    }

    /**
     * Get configured HTTP client with Windows SSL revocation bypass and IPv4 priority.
     */
    protected function getHttpClient(): \Illuminate\Http\Client\PendingRequest
    {
        $curlOptions = defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NO_REVOKE')
            ? [
                CURLOPT_SSL_OPTIONS => CURLSSLOPT_NO_REVOKE,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]
            : [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ];

        return Http::withOptions([
            'curl' => $curlOptions,
            'verify' => false,
            'connect_timeout' => 15,
        ]);
    }

    /**
     * Run an Apify actor synchronously, waiting for completion, and return dataset items.
     */
    protected function runActorAndFetchItems(string $actorId, array $input, int $timeoutSeconds = 180): array
    {
        if (self::isCircuitOpen()) {
            return [];
        }

        if (empty($this->token)) {
            Log::error("Apify API token is missing in .env");
            return [];
        }

        try {
            // Sanitize input arrays: eliminate duplicate and empty items in any scalar list (e.g. keywords, searchQueries, hashtags, urls)
            foreach ($input as $key => $val) {
                if (is_array($val) && !empty($val) && array_is_list($val)) {
                    $isScalarList = true;
                    foreach ($val as $item) {
                        if (!is_string($item) && !is_numeric($item)) {
                            $isScalarList = false;
                            break;
                        }
                    }
                    if ($isScalarList) {
                        $cleaned = array_map(fn($item) => is_string($item) ? trim($item) : $item, $val);
                        $cleaned = array_filter($cleaned, fn($item) => $item !== '' && $item !== null);
                        $input[$key] = array_values(array_unique($cleaned, SORT_REGULAR));
                    }
                }
            }

            // 1. Trigger Actor Run and wait for finish (replace '/' with '~' for named actors in Apify v2 REST API)
            $normalizedActorId = str_replace('/', '~', $actorId);
            $runUrl = "{$this->baseUrl}/acts/{$normalizedActorId}/runs?token={$this->token}&waitForFinish={$timeoutSeconds}";
            $response = $this->getHttpClient()->timeout($timeoutSeconds + 10)->post($runUrl, $input);

            if (!$response->successful()) {
                Log::error("Apify Actor {$actorId} run failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            $runData = $response->json('data', []);
            $datasetId = $runData['defaultDatasetId'] ?? null;

            if (!$datasetId) {
                Log::warning("Apify Actor {$actorId} returned no defaultDatasetId");
                return [];
            }

            // 2. Fetch dataset items with automatic retry for transient network/SSL fluctuations
            $datasetUrl = "{$this->baseUrl}/datasets/{$datasetId}/items?token={$this->token}";
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $datasetResponse = $this->getHttpClient()
                        ->connectTimeout(15)
                        ->timeout(60)
                        ->get($datasetUrl);

                    if ($datasetResponse->successful()) {
                        self::resetCircuit();
                        return $datasetResponse->json() ?? [];
                    }
                } catch (\Throwable $fetchEx) {
                    if ($attempt === 3) {
                        throw $fetchEx;
                    }
                    usleep(1000000); // 1s backoff before retrying
                }
            }

            Log::error("Apify Actor {$actorId} dataset fetch failed", ['dataset_id' => $datasetId]);
            return [];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Could not resolve host') || str_contains($msg, 'SSL connection timeout') || str_contains($msg, 'Connection was reset') || str_contains($msg, 'Timeout was reached') || str_contains($msg, 'cURL error 6') || str_contains($msg, 'cURL error 28') || str_contains($msg, 'cURL error 35')) {
                self::$circuitBrokenUntil = time() + 60; // Trip circuit breaker for 60s
                Log::warning("Apify Circuit Breaker tripped: Network/DNS connectivity issue ({$msg}). Skipping remote calls for 60s.");
            } else {
                Log::error("Apify Actor {$actorId} exception: " . $msg);
            }
            return [];
        }
    }

    // ==================== KEYWORD SCRAPERS ====================

    public function formatReachMetric(int $views): ?string
    {
        if ($views >= 1000000) {
            return round($views / 1000000, 1) . 'M';
        }
        if ($views >= 1000) {
            $val = round($views / 1000, 1);
            return ($val == intval($val) ? intval($val) : $val) . 'K';
        }
        if ($views > 0) {
            return (string) $views;
        }
        return null;
    }

    public function fetchTwitterPostMetrics(string $url): ?array
    {
        if (!preg_match('/(?:twitter|x)\.com\/([^\/]+)\/status\/(\d+)/i', $url, $m)) {
            return null;
        }

        $username = $m[1];
        $tweetId = $m[2];

        try {
            $res = Http::withoutVerifying()
                ->withHeaders(['User-Agent' => 'TelegramBot (like TwitterBot)'])
                ->timeout(5)
                ->get("https://api.fxtwitter.com/{$username}/status/{$tweetId}");

            if ($res->successful()) {
                $data = $res->json()['tweet'] ?? [];
                if (!empty($data)) {
                    $likes = (int) ($data['likes'] ?? 0);
                    $views = (int) ($data['views'] ?? 0);
                    $retweets = (int) ($data['retweets'] ?? 0);
                    $replies = (int) ($data['replies'] ?? 0);

                    $reach = $this->formatReachMetric($views);
                    $engagement = $likes > 0 ? (string) $likes : ($likes + $retweets + $replies > 0 ? (string) ($likes + $retweets + $replies) : null);

                    return [
                        'likes' => $likes,
                        'views' => $views,
                        'retweets' => $retweets,
                        'replies' => $replies,
                        'reach' => $reach,
                        'engagement' => $engagement,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("fetchTwitterPostMetrics failed for {$url}: " . $e->getMessage());
        }

        return null;
    }

    public function fetchXByKeywords(array $keywords, int $maxItems = 10, ?string $country = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $cacheKey = 'apify_x_kw_' . md5(json_encode([$keywords, $maxItems, $country, $dateFrom, $dateTo]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $sinceClause = $dateFrom ? "since:{$dateFrom}" : "";
        $untilClause = $dateTo ? " until:{$dateTo}" : "";

        $targetedQueries = \App\Support\KeywordRelevanceFilter::generateTargetedSearchQueries($keywords);
        if (empty($targetedQueries)) {
            $targetedQueries = $keywords;
        }

        $queryKeywords = array_map(function($kw) use ($country, $sinceClause, $untilClause) {
            $kw = trim($kw);
            if (str_contains($kw, ' ') && !str_starts_with($kw, '"') && !str_starts_with($kw, '#')) {
                $kw = "\"{$kw}\"";
            }
            $parts = array_filter([$kw, $sinceClause, $untilClause]);
            return implode(' ', $parts);
        }, $targetedQueries);

        $queryKeywords = array_values(array_unique(array_filter($queryKeywords)));

        $input = [
            "keywords" => array_slice($queryKeywords, 0, 10),
            "maxItemsPerKeyword" => $maxItems,
            "sortBy" => "latest",
            "outputFormat" => "json",
            "proxyConfiguration" => ["useApifyProxy" => false],
        ];

        $items = $this->runActorAndFetchItems("8CiMefkv2yLlD7vYl", $input);
        $results = [];

        $fromTs = $dateFrom ? strtotime($dateFrom . ' 00:00:00') : null;
        $toTs = $dateTo ? strtotime($dateTo . ' 23:59:59') : null;

        foreach ($items as $item) {
            $postId = (string)($item['id'] ?? $item['tweet_id'] ?? md5(json_encode($item)));
            $username = $item['author_username'] ?? $item['username'] ?? 'x';
            $author = $item['author_name'] ?? $item['username'] ?? '';
            if (empty(trim($author)) || strtolower($author) === 'unknown' || strtolower($author) === 'unknown user') {
                $author = !empty($username) && $username !== 'x' ? "@{$username}" : 'مغرد في X';
            }
            $text = trim($item['text'] ?? '');
            if (empty($text) || mb_strlen($text) < 5 || strtolower($text) === 'unknown') {
                continue; // Skip tweets without meaningful content
            }

            // Strict Relevance Check: Discard tweets that do not mention the monitored target entity
            if (!\App\Support\KeywordRelevanceFilter::isContentRelevant($text . ' ' . $author, $keywords)) {
                continue;
            }

            $rawCreatedAt = $item['created_at'] ?? $item['date'] ?? '';
            $url = $item['url'] ?? $item['tweet_url'] ?? "https://x.com/{$username}/status/{$postId}";

            $tsVal = $this->parseSocialTimestamp($rawCreatedAt);
            if ($tsVal) {
                if ($fromTs && $tsVal < $fromTs) {
                    continue; // Skip tweets before requested date range
                }
                if ($toTs && $tsVal > $toTs) {
                    continue; // Skip tweets after requested date range
                }
            }

            $createdAt = $tsVal ? date('Y-m-d H:i:s', $tsVal) : ($rawCreatedAt ?: date('Y-m-d H:i:s'));

            $matchedKw = null;
            foreach ($keywords as $k) {
                if (mb_stripos($text, trim($k)) !== false) {
                    $matchedKw = trim($k);
                    break;
                }
            }
            if (!$matchedKw) {
                $matchedKw = trim($keywords[0] ?? '');
            }

            // Extract genuine platform interaction metrics
            $views = (int) (
                $item['views']
                ?? $item['view_count']
                ?? $item['viewCount']
                ?? $item['views_count']
                ?? $item['viewsCount']
                ?? $item['impression_count']
                ?? $item['impressionCount']
                ?? ($item['public_metrics']['impression_count'] ?? 0)
            );

            $likes = (int) (
                $item['likes']
                ?? $item['like_count']
                ?? $item['likeCount']
                ?? $item['likes_count']
                ?? $item['likesCount']
                ?? $item['favorite_count']
                ?? $item['favoriteCount']
                ?? ($item['public_metrics']['like_count'] ?? 0)
            );

            $retweets = (int) (
                $item['retweets']
                ?? $item['retweet_count']
                ?? $item['retweetCount']
                ?? $item['reposts']
                ?? $item['repost_count']
                ?? ($item['public_metrics']['retweet_count'] ?? 0)
            );

            $replies = (int) (
                $item['replies']
                ?? $item['reply_count']
                ?? $item['replyCount']
                ?? ($item['public_metrics']['reply_count'] ?? 0)
            );

            // If metrics are 0 and valid tweet URL exists, fetch live metrics
            if ($views === 0 && $likes === 0 && !empty($url)) {
                $liveMetrics = $this->fetchTwitterPostMetrics($url);
                if ($liveMetrics) {
                    $views = $liveMetrics['views'] ?? $views;
                    $likes = $liveMetrics['likes'] ?? $likes;
                    $retweets = $liveMetrics['retweets'] ?? $retweets;
                    $replies = $liveMetrics['replies'] ?? $replies;
                }
            }

            $reach = $this->formatReachMetric($views);
            $engagement = $likes > 0 ? (string) $likes : ($likes + $retweets + $replies > 0 ? (string) ($likes + $retweets + $replies) : null);

            $results[] = [
                "keyword" => $matchedKw,
                "post_id" => $postId,
                "external_id" => "x_{$postId}",
                "author" => $author,
                "text" => $text,
                "url" => $url,
                "created_at" => $createdAt,
                "platform" => "x",
                "country" => $country ?? "SA",
                "views" => $views,
                "likes" => $likes,
                "retweets" => $retweets,
                "replies" => $replies,
                "reach" => $reach,
                "engagement" => $engagement,
            ];

            if (count($results) >= $maxItems) {
                break;
            }
        }

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    public function fetchInstagramByKeywords(array $keywords, int $maxItems = 10, ?string $country = null, bool $explicitHashtags = false): array
    {
        // Cost optimization: Strip '#' from keywords to perform standard post scraping ($0.0005)
        // instead of triggering expensive Apify hashtag query billing ($0.015 per query).
        $processedKeywords = array_map(function($kw) use ($explicitHashtags) {
            $kw = trim($kw);
            return $explicitHashtags ? $kw : ltrim($kw, '#');
        }, $keywords);
        $processedKeywords = array_values(array_filter($processedKeywords));

        if (empty($processedKeywords)) {
            return [];
        }

        $cacheKey = 'apify_ig_kw_' . md5(json_encode([$processedKeywords, $maxItems, $country, $explicitHashtags]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $targetedQueries = \App\Support\KeywordRelevanceFilter::generateTargetedSearchQueries($processedKeywords);
        if (empty($targetedQueries)) {
            $targetedQueries = $processedKeywords;
        }
        $targetedQueries = array_values(array_unique(array_filter($targetedQueries)));

        $input = [
            "keywords" => array_slice($targetedQueries, 0, 8),
            "getStories" => false,
            "maxItems" => max(20, $maxItems * 2),
            "customMapFunction" => "(object) => { return {...object} }",
        ];

        $items = $this->runActorAndFetchItems("VLKR1emKm1YGLmiuZ", $input);
        $results = [];

        foreach ($items as $item) {
            $owner = $item['owner'] ?? [];
            $username = $owner['username'] ?? $owner['full_name'] ?? '';
            if (empty(trim($username)) || strtolower($username) === 'unknown') {
                $username = 'حساب إنستغرام';
            }
            $text = trim($item['caption'] ?? $item['text'] ?? '');
            if (empty($text) || mb_strlen($text) < 5 || strtolower($text) === 'unknown') {
                continue; // Strictly skip posts without caption/text
            }

            // Strict Relevance Check: Discard posts unrelated to monitored entity
            if (!\App\Support\KeywordRelevanceFilter::isContentRelevant($text . ' ' . $username, $keywords)) {
                continue;
            }

            $createdAt = $item['createdAt'] ?? '';
            $shortCode = $item['shortCode'] ?? $item['id'] ?? md5(json_encode($item));
            $postId = (string)($item['id'] ?? $shortCode);
            $url = $item['url'] ?? "https://www.instagram.com/p/{$shortCode}/";

            $locName = strtolower((string)($item['locationName'] ?? ''));
            if ($country && $locName && !str_contains($locName, strtolower($country))) {
                continue;
            }

            $likes = (int) ($item['likesCount'] ?? $item['like_count'] ?? $item['likes'] ?? 0);
            $comments = (int) ($item['commentsCount'] ?? $item['comment_count'] ?? $item['comments'] ?? 0);
            $views = (int) ($item['videoViewCount'] ?? $item['videoPlayCount'] ?? $item['viewsCount'] ?? $item['views'] ?? 0);
            $reach = $this->formatReachMetric($views);
            $engagement = $likes > 0 ? (string) $likes : ($comments > 0 ? (string) $comments : null);

            $results[] = [
                "post_id" => $postId,
                "external_id" => "insta_{$postId}",
                "author" => $username,
                "text" => $text,
                "url" => $url,
                "created_at" => $createdAt,
                "platform" => "instagram",
                "country" => $country ?? "SA",
                "views" => $views,
                "likes" => $likes,
                "reach" => $reach,
                "engagement" => $engagement,
            ];

            if (count($results) >= $maxItems) {
                break;
            }
        }

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    public function fetchTiktokByKeywords(array $keywords, int $maxItems = 10, ?string $country = null): array
    {
        $cacheKey = 'apify_tt_kw_' . md5(json_encode([$keywords, $maxItems, $country]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $targetedQueries = \App\Support\KeywordRelevanceFilter::generateTargetedSearchQueries($keywords);
        if (empty($targetedQueries)) {
            $targetedQueries = $keywords;
        }
        $targetedQueries = array_values(array_unique(array_filter($targetedQueries)));

        $input = [
            "maxItems" => max(25, $maxItems * 2),
            "keywords" => array_slice($targetedQueries, 0, 8),
            "dateRange" => "THIS_MONTH",
            "sortType" => "DATE_POSTED",
            "customMapFunction" => "(object) => { return {...object} }",
        ];

        $items = $this->runActorAndFetchItems("I9kHWwkx0b4giERt0", $input);
        $results = [];
        $cutoffTime = time() - (30 * 86400);

        foreach ($items as $item) {
            $channel = $item['channel'] ?? [];
            $username = $channel['username'] ?? $channel['nickname'] ?? '';
            if (empty(trim($username)) || strtolower($username) === 'unknown') {
                $username = 'صانع محتوى تيك توك';
            }
            $text = trim($item['title'] ?? $item['text'] ?? $item['desc'] ?? '');
            if (empty($text) || mb_strlen($text) < 5 || strtolower($text) === 'unknown') {
                continue; // Strictly skip videos without text/title
            }

            // Strict Relevance Check: Discard videos unrelated to monitored entity
            if (!\App\Support\KeywordRelevanceFilter::isContentRelevant($text . ' ' . $username, $keywords)) {
                continue;
            }

            $createdAt = $item['uploadedAtFormatted'] ?? $item['createTime'] ?? '';
            $videoId = (string)($item['id'] ?? $item['videoId'] ?? md5(json_encode($item)));
            $url = $item['webVideoUrl'] ?? $item['url'] ?? "https://www.tiktok.com/@{$username}/video/{$videoId}";

            // Freshness validation
            $rawTime = $item['createTime'] ?? $item['uploadedAt'] ?? null;
            $tsVal = null;
            if (is_numeric($rawTime)) {
                $tsVal = intval($rawTime) > 9999999999 ? intval($rawTime / 1000) : intval($rawTime);
            } elseif (!empty($createdAt)) {
                $tsVal = strtotime($createdAt);
            }

            if ($tsVal && $tsVal < $cutoffTime) {
                continue;
            }

            $locationCreated = strtolower((string)($item['locationCreated'] ?? ''));
            if ($country && $locationCreated && !str_contains($locationCreated, strtolower($country))) {
                continue;
            }

            $views = (int) ($item['playCount'] ?? $item['views'] ?? $item['view_count'] ?? 0);
            $likes = (int) ($item['diggCount'] ?? $item['likes'] ?? $item['like_count'] ?? 0);
            $comments = (int) ($item['commentCount'] ?? $item['comments'] ?? 0);
            $reach = $this->formatReachMetric($views);
            $engagement = $likes > 0 ? (string) $likes : ($likes + $comments > 0 ? (string) ($likes + $comments) : null);

            $results[] = [
                "post_id" => $videoId,
                "external_id" => "tiktok_{$videoId}",
                "author" => $username,
                "text" => $text,
                "url" => $url,
                "created_at" => $tsVal ? date('Y-m-d H:i:s', $tsVal) : ($createdAt ?: date('Y-m-d H:i:s')),
                "platform" => "tiktok",
                "country" => $country ?? "SA",
                "views" => $views,
                "likes" => $likes,
                "reach" => $reach,
                "engagement" => $engagement,
            ];

            if (count($results) >= $maxItems) {
                break;
            }
        }

        usort($results, function ($a, $b) {
            return strtotime($b['created_at'] ?? 'now') <=> strtotime($a['created_at'] ?? 'now');
        });

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    public function fetchFacebookByKeywords(array $keywords, int $maxItems = 10, ?string $country = null): array
    {
        $cacheKey = 'apify_fb_kw_' . md5(json_encode([$keywords, $maxItems, $country]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $allResults = [];
        $targetedQueries = \App\Support\KeywordRelevanceFilter::generateTargetedSearchQueries($keywords);
        if (empty($targetedQueries)) {
            $targetedQueries = array_values(array_filter(array_map('trim', $keywords)));
        }
        if (empty($targetedQueries)) {
            return [];
        }

        $cutoffTime = time() - (7 * 86400); // 7-day freshness cutoff
        $perKwLimit = max(5, intval(ceil($maxItems / count($targetedQueries))));

        foreach (array_slice($targetedQueries, 0, 3) as $kw) {
            if (self::isCircuitOpen()) {
                break;
            }

            $input = [
                "query" => $kw,
                "resultsCount" => max(10, $perKwLimit * 2),
                "searchType" => "latest",
            ];

            if ($country) {
                $input["location"] = $country;
            }

            $items = $this->runActorAndFetchItems("TMBawM4LZpKN15DZX", $input);
            if (self::isCircuitOpen()) {
                break;
            }
            $kwCount = 0;

            foreach ($items as $item) {
                $authorData = $item['author'] ?? [];
                $author = $authorData['name'] ?? '';
                if (empty(trim($author)) || strtolower($author) === 'unknown') {
                    $author = 'مستخدم فيسبوك';
                }
                $text = trim($item['postText'] ?? $item['text'] ?? '');
                if (empty($text) || mb_strlen($text) < 5 || strtolower($text) === 'unknown') {
                    continue; // Skip posts without text
                }

                // Strict Relevance Check: Discard posts unrelated to monitored entity
                if (!\App\Support\KeywordRelevanceFilter::isContentRelevant($text . ' ' . $author, $keywords)) {
                    continue;
                }

                $postId = (string)($item['postId'] ?? $item['id'] ?? md5($text));
                $url = $item['url'] ?? $item['postUrl'] ?? "https://www.facebook.com/{$postId}";
                $rawTs = $item['timestamp'] ?? $item['time'] ?? $item['date'] ?? null;

                $tsVal = $this->parseSocialTimestamp($rawTs);
                if ($tsVal && $tsVal < $cutoffTime) {
                    continue;
                }

                $createdAt = $tsVal ? date('Y-m-d H:i:s', $tsVal) : date('Y-m-d H:i:s');

                $likes = (int) ($item['likes'] ?? $item['reactionsCount'] ?? $item['likesCount'] ?? 0);
                $comments = (int) ($item['comments'] ?? $item['commentsCount'] ?? 0);
                $views = (int) ($item['views'] ?? $item['viewCount'] ?? 0);
                $reach = $this->formatReachMetric($views);
                $engagement = $likes > 0 ? (string) $likes : ($likes + $comments > 0 ? (string) ($likes + $comments) : null);

                $allResults[] = [
                    "keyword" => $kw,
                    "post_id" => $postId,
                    "external_id" => "fb_{$postId}",
                    "author" => $author,
                    "text" => $text,
                    "url" => $url,
                    "created_at" => $createdAt,
                    "platform" => "facebook",
                    "country" => $country ?? "SA",
                    "views" => $views,
                    "likes" => $likes,
                    "reach" => $reach,
                    "engagement" => $engagement,
                ];

                $kwCount++;
                if ($kwCount >= $perKwLimit || count($allResults) >= $maxItems) {
                    break;
                }
            }

            if (count($allResults) >= $maxItems) {
                break;
            }
        }

        usort($allResults, function ($a, $b) {
            return strtotime($b['created_at'] ?? 'now') <=> strtotime($a['created_at'] ?? 'now');
        });

        if (!empty($allResults)) {
            Cache::put($cacheKey, $allResults, now()->addHours(6));
        }

        return $allResults;
    }

    // ==================== URL COMMENT SCRAPERS ====================

    public function fetchInstagramComments(array $urls, int $limit = 100): array
    {
        if (empty($urls)) return [];

        $cacheKey = 'apify_ig_comments_' . md5(json_encode([$urls, $limit]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $input = [
            "resultsType" => "comments",
            "directUrls" => $urls,
            "resultsLimit" => $limit,
            "searchLimit" => $limit,
            "addParentData" => false,
        ];

        $items = $this->runActorAndFetchItems("shu8hvrXbJbY3Eb9W", $input);
        $results = [];

        foreach ($items as $item) {
            $profileName = $item['ownerUsername'] ?? $item['owner']['username'] ?? $item['name'] ?? $item['username'] ?? "Unknown User";
            $text = $item['text'] ?? $item['commentText'] ?? $item['caption'] ?? "";
            if (trim($text) !== '') {
                $results[] = "{$profileName}:\n\n {$text}";
            }
        }

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    public function fetchFacebookComments(array $urls, int $limit = 100): array
    {
        if (empty($urls)) return [];

        $cacheKey = 'apify_fb_comments_' . md5(json_encode([$urls, $limit]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $startUrls = array_map(fn($u) => ["url" => $u, "platform" => "FACEBOOK"], $urls);
        $input = [
            "startUrls" => $startUrls,
            "resultsLimit" => $limit,
            "includeNestedComments" => true,
            "viewOption" => "RANKED_UNFILTERED",
        ];

        $items = $this->runActorAndFetchItems("apify/facebook-comments-scraper", $input);
        $results = [];

        foreach ($items as $item) {
            $profileName = $item['profileName'] ?? $item['user'] ?? $item['author'] ?? $item['name'] ?? "Unknown User";
            $text = $item['commentText'] ?? $item['text'] ?? $item['message'] ?? $item['comment'] ?? "";
            if (trim($text) !== '') {
                $results[] = "{$profileName}:\n\n {$text}";
            }
        }

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    public function fetchTiktokComments(array $urls, int $limit = 100): array
    {
        if (empty($urls)) return [];

        $cacheKey = 'apify_tt_comments_' . md5(json_encode([$urls, $limit]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $input = [
            "videoUrls" => $urls,
            "maxCommentsPerVideo" => $limit,
            "includeReplies" => true,
            "maxRepliesPerComment" => 20,
        ];

        $items = $this->runActorAndFetchItems("CV8JKMosNLDGqWjQo", $input);
        $results = [];

        foreach ($items as $item) {
            $profileName = $item['username'] ?? $item['name'] ?? $item['user']['unique_id'] ?? "Unknown User";
            $text = $item['text'] ?? $item['commentText'] ?? $item['comment'] ?? "";
            if (trim($text) !== '') {
                $results[] = "{$profileName}:\n\n {$text}";
            }
        }

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    public function fetchTwitterComments(array $urls, int $limit = 100): array
    {
        if (empty($urls)) return [];

        $cacheKey = 'apify_x_comments_' . md5(json_encode([$urls, $limit]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $input = [
            "postUrls" => $urls,
            "resultsLimit" => $limit,
            "includeOriginalPost" => false,
        ];

        $items = $this->runActorAndFetchItems("qhybbvlFivx7AP0Oh", $input);
        $results = [];

        foreach ($items as $item) {
            $author = $item['author'] ?? [];
            $profileName = $author['name'] ?? $author['username'] ?? $item['name'] ?? "Unknown User";
            $text = $item['text'] ?? $item['replyText'] ?? $item['full_text'] ?? "";
            if (trim($text) !== '') {
                $results[] = "{$profileName}:\n\n {$text}";
            }
        }

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    // ==================== TOPIC / INDUSTRY TREND SCRAPERS ====================

    /**
     * Fetch trending Facebook posts based on keywords with dynamic limits.
     */
    public function fetchFacebookPostsTrending(array $keywords, int $maxPosts = 50): array
    {
        if (empty($keywords)) return [];

        $cacheKey = 'apify_fb_trend_' . md5(json_encode([$keywords, $maxPosts]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Check if APIFY_TOKEN_3 is available for IKvFqAEWd6Ms91VsH
        $customToken = env('APIFY_TOKEN_3');
        if (!empty($customToken)) {
            $input = [
                "searchQueries" => array_values($keywords),
                "maxPosts" => max(1, $maxPosts),
                "postTimeRange" => "",
                "maxCommentsPerPost" => 0,
                "proxyConfiguration" => ["useApifyProxy" => true],
            ];
            $items = $this->runActorAndFetchItems("IKvFqAEWd6Ms91VsH", $input);
            if (!empty($items)) {
                $results = [];
                foreach ($items as $item) {
                    $text = $item['text'] ?? '';
                    if (!empty($text)) {
                        $results[] = [
                            "platform" => "facebook",
                            "username" => $item['pageName'] ?? $item['author'] ?? 'Facebook User',
                            "text" => $text,
                            "time" => $item['time'] ?? null,
                            "url" => $item['url'] ?? null,
                        ];
                    }
                }
                if (!empty($results)) {
                    Cache::put($cacheKey, $results, now()->addHours(6));
                }
                return $results;
            }
        }

        // Standard Facebook posts search actor (TMBawM4LZpKN15DZX)
        $primaryKeyword = trim($keywords[0] ?? 'trend');
        if (str_contains($primaryKeyword, ' ') && !str_starts_with($primaryKeyword, '"') && !str_starts_with($primaryKeyword, '#')) {
            $queryString = "\"{$primaryKeyword}\"";
        } else {
            $queryString = $primaryKeyword;
        }

        $input = [
            "query" => $queryString,
            "resultsCount" => max(1, $maxPosts),
            "searchType" => "top", // Prioritize top posts over raw latest
        ];

        $items = $this->runActorAndFetchItems("TMBawM4LZpKN15DZX", $input);
        if (empty($items)) {
            // Fallback to latest search if popular returns empty
            $input["searchType"] = "latest";
            $items = $this->runActorAndFetchItems("TMBawM4LZpKN15DZX", $input);
        }

        $results = [];
        $cutoffTime = time() - (3 * 86400); // Strict 3-day cutoff for trends ("بتاعت النهاردة")

        foreach ($items as $item) {
            $authorData = $item['author'] ?? [];
            $author = $authorData['name'] ?? $item['pageName'] ?? 'Facebook User';
            $text = $item['postText'] ?? $item['text'] ?? '';
            $url = $item['url'] ?? $item['postUrl'] ?? null;
            $rawTs = $item['timestamp'] ?? $item['time'] ?? $item['date'] ?? null;

            // Extract engagement metrics
            $likes = intval($item['likesCount'] ?? $item['likes'] ?? $item['reactionCount'] ?? 0);
            $comments = intval($item['commentsCount'] ?? $item['comments'] ?? 0);
            $shares = intval($item['sharesCount'] ?? $item['shares'] ?? 0);
            $engagement = $likes + $comments + $shares;

            // Reject if text, url, or raw date clearly contains old years (e.g. 2025, 2024, 2023)
            $combined = $text . ' ' . ($url ?? '') . ' ' . (is_string($rawTs) ? $rawTs : '');
            if (preg_match('/\b(201\d|202[0-5])\b/', $combined)) {
                continue; // Strictly reject stale posts from previous years!
            }

            // Extract and parse real post timestamp
            $tsVal = $this->parseSocialTimestamp($rawTs);

            if ($tsVal && $tsVal < $cutoffTime) {
                continue;
            }

            $createdAt = $tsVal ? date('Y-m-d H:i:s', $tsVal) : date('Y-m-d H:i:s');

            if (!empty($text)) {
                $results[] = [
                    "platform" => "facebook",
                    "username" => $author,
                    "text" => $text,
                    "time" => $createdAt,
                    "url" => $url,
                    "engagement" => $engagement,
                    "likes" => $likes,
                    "comments" => $comments,
                    "shares" => $shares,
                ];
            }
        }

        usort($results, function ($a, $b) {
            // Sort primary by engagement then by timestamp
            if (($b['engagement'] ?? 0) !== ($a['engagement'] ?? 0)) {
                return ($b['engagement'] ?? 0) <=> ($a['engagement'] ?? 0);
            }
            return strtotime($b['time'] ?? 'now') <=> strtotime($a['time'] ?? 'now');
        });

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    /**
     * Fetch trending Instagram posts based on keywords with dynamic limits.
     * By default, performs standard post search ($0.0005 per item) instead of expensive hashtag query ($0.015 per query).
     */
    public function fetchInstagramPostsTrending(array $keywords, int $resultsLimit = 50, bool $explicitHashtags = false): array
    {
        if (empty($keywords)) return [];

        // Cost optimization: Hashtag queries cost $0.015, while normal post extraction costs $0.0005 (30x cheaper).
        // Default to normal post extraction unless hashtags are explicitly requested by client.
        if (!$explicitHashtags) {
            return $this->fetchInstagramByKeywords($keywords, $resultsLimit, null, false);
        }

        // Clean hashtags if they include '#'
        $cleanHashtags = array_map(fn($k) => ltrim($k, '#'), $keywords);
        $cacheKey = 'apify_ig_trend_ht_' . md5(json_encode([$cleanHashtags, $resultsLimit]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $input = [
            "hashtags" => array_values($cleanHashtags),
            "keywordSearch" => true,
            "resultsType" => "posts",
            "resultsLimit" => max(1, $resultsLimit),
        ];

        $items = $this->runActorAndFetchItems("reGe1ST3OBgYZSsZJ", $input);

        $results = [];

        foreach ($items as $item) {
            $caption = $item['caption'] ?? '';
            $likes = intval($item['likesCount'] ?? $item['likes'] ?? 0);
            $comments = intval($item['commentsCount'] ?? $item['comments'] ?? 0);
            $engagement = $likes + $comments;

            if (!empty($caption)) {
                $results[] = [
                    "platform" => "instagram",
                    "username" => $item['ownerUsername'] ?? 'Instagram User',
                    "text" => $caption,
                    "time" => $item['timestamp'] ?? null,
                    "url" => $item['url'] ?? null,
                    "engagement" => $engagement,
                    "likes" => $likes,
                    "comments" => $comments,
                ];
            }
        }

        usort($results, function ($a, $b) {
            return ($b['engagement'] ?? 0) <=> ($a['engagement'] ?? 0);
        });

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    /**
     * Fetch trending TikTok videos based on keywords with dynamic limits.
     */
    public function fetchTiktokTrending(array $keywords, int $maxItems = 50): array
    {
        if (empty($keywords)) return [];

        $cacheKey = 'apify_tt_trend_' . md5(json_encode([$keywords, $maxItems]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $input = [
            "keywords" => array_values($keywords),
            "searchType" => "video",
            "maxItemsPerKeyword" => max(1, $maxItems),
            "sort" => "mostLiked",
            "region" => "",
            "datePosted" => "last30Days",
            "deduplicateAcrossKeywords" => true,
            "includeKeywordInsights" => false,
            "includeDownloadUrl" => false,
        ];

        $items = $this->runActorAndFetchItems("APtXyRPRKLLe8yrXg", $input);
        if (empty($items)) {
            $input["sort"] = "latest";
            $items = $this->runActorAndFetchItems("APtXyRPRKLLe8yrXg", $input);
        }

        $results = [];

        foreach ($items as $item) {
            $text = $item['caption'] ?? $item['text'] ?? '';
            $likes = intval($item['diggCount'] ?? $item['likes'] ?? 0);
            $views = intval($item['playCount'] ?? $item['views'] ?? 0);
            $shares = intval($item['shareCount'] ?? 0);
            $comments = intval($item['commentCount'] ?? 0);
            $engagement = $likes + $shares + $comments;

            if (!empty($text)) {
                $results[] = [
                    "platform" => "tiktok",
                    "username" => $item['authorUniqueId'] ?? $item['username'] ?? 'TikTok User',
                    "text" => $text,
                    "time" => $item['createTimeISO'] ?? null,
                    "url" => $item['videoUrl'] ?? ($item['Url'] ?? null),
                    "engagement" => $engagement,
                    "likes" => $likes,
                    "views" => $views,
                    "shares" => $shares,
                ];
            }
        }

        usort($results, function ($a, $b) {
            return ($b['engagement'] ?? 0) <=> ($a['engagement'] ?? 0);
        });

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    /**
     * Fetch real-time Twitter / X country trends.
     */
    public function fetchTwitterTrends(array $locations = ["SA", "EG"], int $maxTrends = 50): array
    {
        $cacheKey = 'apify_x_trends_' . md5(json_encode([$locations, $maxTrends]));
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $input = [
            "locations" => $locations,
            "maxTrendsPerLocation" => min(50, max(1, $maxTrends)),
            "getAvailableLocations" => false,
        ];

        $items = $this->runActorAndFetchItems("QCa3oZUDI3nnIO59X", $input);
        $results = [];

        foreach ($items as $item) {
            $trendName = $item['name'] ?? '';
            $volume = intval($item['tweetVolume'] ?? $item['volume'] ?? 10000);
            if (!empty($trendName)) {
                $results[] = [
                    "platform" => "twitter",
                    "trend_name" => $trendName,
                    "time" => $item['asOf'] ?? null,
                    "trendUrl" => $item['twitterSearchUrl'] ?? null,
                    "engagement" => $volume,
                    "volume" => $volume,
                ];
            }
        }

        if (!empty($results)) {
            Cache::put($cacheKey, $results, now()->addHours(6));
        }

        return $results;
    }

    /**
     * Parse any social media timestamp or localized date string (including Arabic and relative times).
     */
    public function parseSocialTimestamp(mixed $raw): ?int
    {
        if (empty($raw)) return null;
        if (is_numeric($raw)) {
            $val = intval($raw);
            if ($val > 9999999999) {
                $val = intval($val / 1000);
            }
            if ($val >= 2000 && $val <= 2100) {
                return time() - rand(3600, 86400);
            }
            if ($val < 946684800) {
                return null;
            }
            return $val;
        }

        $str = trim((string)$raw);

        // Normalize Arabic-Indic digits
        $arabicNums = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $asciiNums = ['0','1','2','3','4','5','6','7','8','9'];
        $str = str_replace($arabicNums, $asciiNums, $str);

        // Explicit detection of past years (e.g. 2024, 2025 in year 2026)
        if (preg_match('/\b(201\d|202[0-5])\b/', $str, $matches)) {
            $oldYear = $matches[1];
            return strtotime($oldYear . '-06-01');
        }

        // Replace Arabic months with English equivalents
        $months = [
            'يناير' => 'January', 'فبراير' => 'February', 'مارس' => 'March',
            'أبريل' => 'April', 'ابريل' => 'April', 'مايو' => 'May',
            'يونيو' => 'June', 'يوليو' => 'July', 'أغسطس' => 'August',
            'اغسطس' => 'August', 'سبتمبر' => 'September', 'أكتوبر' => 'October',
            'اكتوبر' => 'October', 'نوفمبر' => 'November', 'ديسمبر' => 'December',
        ];
        $strEng = str_replace(array_keys($months), array_values($months), $str);

        // Relative Arabic phrases
        if (preg_match('/منذ\s+(\d+)\s*(ساعة|ساعات|س)/u', $str, $m)) {
            return strtotime('-' . $m[1] . ' hours');
        }
        if (preg_match('/منذ\s+(\d+)\s*(دقيقة|دقائق|د)/u', $str, $m)) {
            return strtotime('-' . $m[1] . ' minutes');
        }
        if (preg_match('/منذ\s+(\d+)\s*(يوم|أيام|ايام)/u', $str, $m)) {
            return strtotime('-' . $m[1] . ' days');
        }
        if (str_contains($str, 'ساعتين')) return strtotime('-2 hours');
        if (str_contains($str, 'يومين')) return strtotime('-2 days');
        if (str_contains($str, 'أمس') || str_contains($str, 'امس')) return strtotime('-1 day');
        if (str_contains($str, 'الآن') || str_contains($str, 'الان')) return time();

        $cleanStr = preg_replace('/[^\w\s:,\-\/]/u', ' ', $strEng);
        $cleanStr = trim(preg_replace('/\s+/', ' ', $cleanStr));
        $parsed = strtotime($cleanStr);
        $res = $parsed ?: (strtotime($str) ?: null);
        if ($res && $res < 946684800) {
            return null;
        }

        return $res;
    }
}

