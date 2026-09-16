<?php

namespace App\Services;

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
            'connect_timeout' => 30,
        ]);
    }

    /**
     * Run an Apify actor synchronously, waiting for completion, and return dataset items.
     */
    protected function runActorAndFetchItems(string $actorId, array $input, int $timeoutSeconds = 180): array
    {
        if (empty($this->token)) {
            Log::error("Apify API token is missing in .env");
            return [];
        }

        try {
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

            // 2. Fetch dataset items
            $datasetUrl = "{$this->baseUrl}/datasets/{$datasetId}/items?token={$this->token}";
            $datasetResponse = $this->getHttpClient()->timeout(60)->get($datasetUrl);

            if ($datasetResponse->successful()) {
                return $datasetResponse->json() ?? [];
            }

            Log::error("Apify Actor {$actorId} dataset fetch failed", ['dataset_id' => $datasetId]);
            return [];
        } catch (\Exception $e) {
            Log::error("Apify Actor {$actorId} exception: " . $e->getMessage());
            return [];
        }
    }

    // ==================== KEYWORD SCRAPERS ====================

    public function fetchXByKeywords(array $keywords, int $maxItems = 10, ?string $country = null): array
    {
        $sinceDate = date('Y-m-d', strtotime('-2 days'));
        $queryKeywords = array_map(function($kw) use ($country, $sinceDate) {
            $kw = trim($kw);
            if (str_contains($kw, ' ') && !str_starts_with($kw, '"') && !str_starts_with($kw, '#')) {
                $kw = "\"{$kw}\"";
            }
            return "{$kw} since:{$sinceDate}";
        }, $keywords);

        $input = [
            "keywords" => $queryKeywords,
            "maxItemsPerKeyword" => $maxItems,
            "sortBy" => "latest",
            "outputFormat" => "json",
            "proxyConfiguration" => ["useApifyProxy" => false],
        ];

        $items = $this->runActorAndFetchItems("8CiMefkv2yLlD7vYl", $input);
        $results = [];
        $cutoffTime = time() - (48 * 3600); // Strict 48h recency cutoff (today and yesterday only)

        foreach ($items as $item) {
            $postId = (string)($item['id'] ?? $item['tweet_id'] ?? md5(json_encode($item)));
            $author = $item['author_name'] ?? $item['username'] ?? 'Unknown User';
            $text = $item['text'] ?? '';
            $rawCreatedAt = $item['created_at'] ?? $item['date'] ?? '';
            $username = $item['author_username'] ?? $item['username'] ?? 'x';
            $url = $item['url'] ?? $item['tweet_url'] ?? "https://x.com/{$username}/status/{$postId}";

            $tsVal = $this->parseSocialTimestamp($rawCreatedAt);
            if ($tsVal && $tsVal < $cutoffTime) {
                continue; // Skip tweets older than 48 hours
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

            $results[] = [
                "keyword" => $matchedKw,
                "post_id" => $postId,
                "external_id" => "x_{$postId}",
                "author" => $author,
                "text" => $text,
                "url" => $url,
                "created_at" => $createdAt,
                "platform" => "x",
                "country" => $country ?? "SA"
            ];
        }

        return $results;
    }

    public function fetchInstagramByKeywords(array $keywords, int $maxItems = 10, ?string $country = null): array
    {
        $input = [
            "keywords" => $keywords,
            "getStories" => true,
            "maxItems" => $maxItems,
            "customMapFunction" => "(object) => { return {...object} }",
        ];

        $items = $this->runActorAndFetchItems("VLKR1emKm1YGLmiuZ", $input);
        $results = [];

        foreach ($items as $item) {
            $owner = $item['owner'] ?? [];
            $username = $owner['username'] ?? 'Unknown';
            $text = $item['caption'] ?? '';
            $createdAt = $item['createdAt'] ?? '';
            $shortCode = $item['shortCode'] ?? $item['id'] ?? md5(json_encode($item));
            $postId = (string)($item['id'] ?? $shortCode);
            $url = $item['url'] ?? "https://www.instagram.com/p/{$shortCode}/";

            $locName = strtolower((string)($item['locationName'] ?? ''));
            if ($country && $locName && !str_contains($locName, strtolower($country))) {
                continue;
            }

            $results[] = [
                "post_id" => $postId,
                "external_id" => "insta_{$postId}",
                "author" => $username,
                "text" => $text,
                "url" => $url,
                "created_at" => $createdAt,
                "platform" => "instagram",
                "country" => $country ?? "SA"
            ];
        }

        return $results;
    }

    public function fetchTiktokByKeywords(array $keywords, int $maxItems = 10, ?string $country = null): array
    {
        $input = [
            "maxItems" => max(15, $maxItems * 2),
            "keywords" => $keywords,
            "dateRange" => "THIS_MONTH",
            "sortType" => "DATE_POSTED",
            "customMapFunction" => "(object) => { return {...object} }",
        ];

        $items = $this->runActorAndFetchItems("I9kHWwkx0b4giERt0", $input);
        $results = [];
        $cutoffTime = time() - (30 * 86400);

        foreach ($items as $item) {
            $channel = $item['channel'] ?? [];
            $username = $channel['username'] ?? 'Unknown';
            $text = $item['title'] ?? $item['text'] ?? '';
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

            $results[] = [
                "post_id" => $videoId,
                "external_id" => "tiktok_{$videoId}",
                "author" => $username,
                "text" => $text,
                "url" => $url,
                "created_at" => $tsVal ? date('Y-m-d H:i:s', $tsVal) : ($createdAt ?: date('Y-m-d H:i:s')),
                "platform" => "tiktok",
                "country" => $country ?? "SA"
            ];

            if (count($results) >= $maxItems) {
                break;
            }
        }

        usort($results, function ($a, $b) {
            return strtotime($b['created_at'] ?? 'now') <=> strtotime($a['created_at'] ?? 'now');
        });

        return $results;
    }

    public function fetchFacebookByKeywords(array $keywords, int $maxItems = 10, ?string $country = null): array
    {
        $allResults = [];
        $cleanKeywords = array_values(array_filter(array_map('trim', $keywords)));
        if (empty($cleanKeywords)) {
            return [];
        }

        $cutoffTime = time() - (7 * 86400); // 7-day freshness cutoff
        $perKwLimit = max(5, intval(ceil($maxItems / count($cleanKeywords))));

        foreach ($cleanKeywords as $kw) {
            $input = [
                "query" => $kw,
                "resultsCount" => max(10, $perKwLimit * 2),
                "searchType" => "latest",
            ];

            if ($country) {
                $input["location"] = $country;
            }

            $items = $this->runActorAndFetchItems("TMBawM4LZpKN15DZX", $input);
            $kwCount = 0;

            foreach ($items as $item) {
                $authorData = $item['author'] ?? [];
                $author = $authorData['name'] ?? 'Unknown';
                $text = $item['postText'] ?? $item['text'] ?? '';
                $postId = (string)($item['postId'] ?? $item['id'] ?? md5($text));
                $url = $item['url'] ?? $item['postUrl'] ?? "https://www.facebook.com/{$postId}";
                $rawTs = $item['timestamp'] ?? $item['time'] ?? $item['date'] ?? null;

                $tsVal = $this->parseSocialTimestamp($rawTs);
                if ($tsVal && $tsVal < $cutoffTime) {
                    continue;
                }

                $createdAt = $tsVal ? date('Y-m-d H:i:s', $tsVal) : date('Y-m-d H:i:s');

                $allResults[] = [
                    "keyword" => $kw,
                    "post_id" => $postId,
                    "external_id" => "fb_{$postId}",
                    "author" => $author,
                    "text" => $text,
                    "url" => $url,
                    "created_at" => $createdAt,
                    "platform" => "facebook",
                    "country" => $country ?? "SA"
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

        return $allResults;
    }

    // ==================== URL COMMENT SCRAPERS ====================

    public function fetchInstagramComments(array $urls, int $limit = 100): array
    {
        if (empty($urls)) return [];
        $input = [
            "resultsType" => "comments",
            "directUrls" => $urls,
            "resultsLimit" => $limit,
            "searchType" => "hashtag",
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

        return $results;
    }

    public function fetchFacebookComments(array $urls, int $limit = 100): array
    {
        if (empty($urls)) return [];
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

        return $results;
    }

    public function fetchTiktokComments(array $urls, int $limit = 100): array
    {
        if (empty($urls)) return [];
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

        return $results;
    }

    public function fetchTwitterComments(array $urls, int $limit = 100): array
    {
        if (empty($urls)) return [];
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

        return $results;
    }

    // ==================== TOPIC / INDUSTRY TREND SCRAPERS ====================

    /**
     * Fetch trending Facebook posts based on keywords with dynamic limits.
     */
    public function fetchFacebookPostsTrending(array $keywords, int $maxPosts = 50): array
    {
        if (empty($keywords)) return [];

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

        return $results;
    }

    /**
     * Fetch trending Instagram posts based on hashtags with dynamic limits.
     */
    public function fetchInstagramPostsTrending(array $keywords, int $resultsLimit = 50): array
    {
        if (empty($keywords)) return [];

        // Clean hashtags if they include '#'
        $cleanHashtags = array_map(fn($k) => ltrim($k, '#'), $keywords);

        $input = [
            "hashtags" => array_values($cleanHashtags),
            "keywordSearch" => true,
            "resultsType" => "top_posts", // Target top/viral posts instead of standard feed
            "resultsLimit" => max(1, $resultsLimit),
        ];

        $items = $this->runActorAndFetchItems("reGe1ST3OBgYZSsZJ", $input);
        if (empty($items)) {
            $input["resultsType"] = "posts";
            $items = $this->runActorAndFetchItems("reGe1ST3OBgYZSsZJ", $input);
        }

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

        return $results;
    }

    /**
     * Fetch trending TikTok videos based on keywords with dynamic limits.
     */
    public function fetchTiktokTrending(array $keywords, int $maxItems = 50): array
    {
        if (empty($keywords)) return [];

        $input = [
            "keywords" => array_values($keywords),
            "searchType" => "video",
            "maxItemsPerKeyword" => max(1, $maxItems),
            "sort" => "popular", // Sort by popularity / engagement
            "region" => "",
            "datePosted" => "this-month",
            "deduplicateAcrossKeywords" => true,
            "includeKeywordInsights" => false,
            "includeDownloadUrl" => false,
        ];

        $items = $this->runActorAndFetchItems("APtXyRPRKLLe8yrXg", $input);
        if (empty($items)) {
            $input["sort"] = "date";
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

        return $results;
    }

    /**
     * Fetch real-time Twitter / X country trends.
     */
    public function fetchTwitterTrends(array $locations = ["SA", "EG"], int $maxTrends = 50): array
    {
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
            return $val > 9999999999 ? intval($val / 1000) : $val;
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

        return $parsed ?: (strtotime($str) ?: null);
    }
}

