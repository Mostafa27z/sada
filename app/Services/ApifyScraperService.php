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
        $this->token = env('APIFY_TOKEN_2') ?: env('APIFY_TOKEN', '');
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
        $queryKeywords = $keywords;
        if ($country) {
            $queryKeywords = array_map(fn($kw) => "{$kw} near:\"{$country}\"", $keywords);
        }

        $input = [
            "keywords" => $queryKeywords,
            "maxItemsPerKeyword" => $maxItems,
            "sortBy" => "latest",
            "outputFormat" => "json",
            "proxyConfiguration" => ["useApifyProxy" => false],
        ];

        $items = $this->runActorAndFetchItems("8CiMefkv2yLlD7vYl", $input);
        $results = [];

        foreach ($items as $item) {
            $postId = (string)($item['id'] ?? $item['tweet_id'] ?? md5(json_encode($item)));
            $author = $item['author_name'] ?? $item['username'] ?? 'Unknown User';
            $text = $item['text'] ?? '';
            $createdAt = $item['created_at'] ?? '';
            $username = $item['author_username'] ?? $item['username'] ?? 'x';
            $url = $item['url'] ?? $item['tweet_url'] ?? "https://x.com/{$username}/status/{$postId}";

            $results[] = [
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
            "maxItems" => $maxItems,
            "keywords" => $keywords,
            "dateRange" => "DEFAULT",
            "sortType" => "RELEVANCE",
            "customMapFunction" => "(object) => { return {...object} }",
        ];

        $items = $this->runActorAndFetchItems("I9kHWwkx0b4giERt0", $input);
        $results = [];

        foreach ($items as $item) {
            $channel = $item['channel'] ?? [];
            $username = $channel['username'] ?? 'Unknown';
            $text = $item['title'] ?? $item['text'] ?? '';
            $createdAt = $item['uploadedAtFormatted'] ?? $item['createTime'] ?? '';
            $videoId = (string)($item['id'] ?? $item['videoId'] ?? md5(json_encode($item)));
            $url = $item['webVideoUrl'] ?? $item['url'] ?? "https://www.tiktok.com/@{$username}/video/{$videoId}";

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
                "created_at" => $createdAt,
                "platform" => "tiktok",
                "country" => $country ?? "SA"
            ];
        }

        return $results;
    }

    public function fetchFacebookByKeywords(array $keywords, int $maxItems = 10, ?string $country = null): array
    {
        $input = [
            "query" => implode("  ", $keywords),
            "resultsCount" => $maxItems,
            "searchType" => "latest",
        ];

        if ($country) {
            $input["location"] = $country;
        }

        $items = $this->runActorAndFetchItems("TMBawM4LZpKN15DZX", $input);
        $results = [];

        foreach ($items as $item) {
            $authorData = $item['author'] ?? [];
            $author = $authorData['name'] ?? 'Unknown';
            $text = $item['postText'] ?? $item['text'] ?? '';
            $timestamp = $item['timestamp'] ?? null;
            $createdAt = $timestamp ? date('Y-m-d H:i:s', intval($timestamp / 1000)) : '';
            $postId = (string)($item['postId'] ?? $item['id'] ?? md5($text));
            $url = $item['url'] ?? $item['postUrl'] ?? "https://www.facebook.com/{$postId}";

            $results[] = [
                "post_id" => $postId,
                "external_id" => "fb_{$postId}",
                "author" => $author,
                "text" => $text,
                "url" => $url,
                "created_at" => $createdAt,
                "platform" => "facebook",
                "country" => $country ?? "SA"
            ];
        }

        return $results;
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
        $queryTerms = [];
        $totalLen = 0;
        foreach ($keywords as $kw) {
            $kw = trim($kw);
            if ($totalLen + mb_strlen($kw) + 1 <= 90) {
                $queryTerms[] = $kw;
                $totalLen += mb_strlen($kw) + 1;
            } else {
                break;
            }
        }
        $queryString = !empty($queryTerms) ? implode(" ", $queryTerms) : mb_substr($keywords[0] ?? 'trend', 0, 90);

        $input = [
            "query" => $queryString,
            "resultsCount" => max(1, $maxPosts),
            "searchType" => "latest",
        ];

        $items = $this->runActorAndFetchItems("TMBawM4LZpKN15DZX", $input);
        $results = [];

        foreach ($items as $item) {
            $authorData = $item['author'] ?? [];
            $author = $authorData['name'] ?? $item['pageName'] ?? 'Facebook User';
            $text = $item['postText'] ?? $item['text'] ?? '';
            $timestamp = $item['timestamp'] ?? null;
            $createdAt = $timestamp ? date('Y-m-d H:i:s', intval($timestamp / 1000)) : null;
            $postId = (string)($item['postId'] ?? $item['id'] ?? md5($text));
            $url = $item['url'] ?? $item['postUrl'] ?? null;

            if (!empty($text)) {
                $results[] = [
                    "platform" => "facebook",
                    "username" => $author,
                    "text" => $text,
                    "time" => $createdAt,
                    "url" => $url,
                ];
            }
        }

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
            "resultsType" => "posts",
            "resultsLimit" => max(1, $resultsLimit),
        ];

        $items = $this->runActorAndFetchItems("reGe1ST3OBgYZSsZJ", $input);
        $results = [];

        foreach ($items as $item) {
            $caption = $item['caption'] ?? '';
            if (!empty($caption)) {
                $results[] = [
                    "platform" => "instagram",
                    "username" => $item['ownerUsername'] ?? 'Instagram User',
                    "text" => $caption,
                    "time" => $item['timestamp'] ?? null,
                    "url" => $item['url'] ?? null,
                ];
            }
        }

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
            "sort" => "relevance",
            "region" => "",
            "datePosted" => "any",
            "deduplicateAcrossKeywords" => false,
            "includeKeywordInsights" => false,
            "includeDownloadUrl" => false,
        ];

        $items = $this->runActorAndFetchItems("APtXyRPRKLLe8yrXg", $input);
        $results = [];

        foreach ($items as $item) {
            $text = $item['caption'] ?? $item['text'] ?? '';
            if (!empty($text)) {
                $results[] = [
                    "platform" => "tiktok",
                    "username" => $item['authorUniqueId'] ?? $item['username'] ?? 'TikTok User',
                    "text" => $text,
                    "time" => $item['createTimeISO'] ?? null,
                    "url" => $item['videoUrl'] ?? ($item['Url'] ?? null),
                ];
            }
        }

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
            if (!empty($trendName)) {
                $results[] = [
                    "platform" => "twitter",
                    "trend_name" => $trendName,
                    "time" => $item['asOf'] ?? null,
                    "trendUrl" => $item['twitterSearchUrl'] ?? null,
                ];
            }
        }

        return $results;
    }
}
