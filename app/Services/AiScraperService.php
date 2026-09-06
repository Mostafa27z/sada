<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiScraperService
{
    protected string $baseUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('microservice.url', 'http://127.0.0.1:8000');
        $this->timeout = config('microservice.timeout', 120);
    }

    /**
     * Scrape posts and generate sentiment analytics by keywords across platforms.
     */
    public function scrapeByKeywords(array $keywords, array $platforms = ['instagram', 'facebook', 'x', 'tiktok'], ?string $country = null): array
    {
        $url = rtrim($this->baseUrl, '/') . '/analyze-by-keywords';

        try {
            $payload = [
                'keywords' => $keywords,
                'platforms' => $platforms,
            ];

            if ($country) {
                $payload['country'] = $country;
            }

            $response = Http::connectTimeout(10)->timeout($this->timeout)->post($url, $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('AI Microservice error on scrapeByKeywords', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to retrieve keyword scrape results from AI microservice.',
            ];
        } catch (\Exception $e) {
            Log::error('AI Microservice exception on scrapeByKeywords: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Scrape post comments and sentiment analytics by URLs.
     */
    public function scrapePostComments(array $urlsByPlatform): array
    {
        $url = rtrim($this->baseUrl, '/') . '/analyze';

        $payload = [
            'insta_urls' => $urlsByPlatform['insta_urls'] ?? $urlsByPlatform['instagram'] ?? [],
            'facebook_urls' => $urlsByPlatform['facebook_urls'] ?? $urlsByPlatform['facebook'] ?? [],
            'tiktok_urls' => $urlsByPlatform['tiktok_urls'] ?? $urlsByPlatform['tiktok'] ?? [],
            'twitter_urls' => $urlsByPlatform['twitter_urls'] ?? $urlsByPlatform['twitter'] ?? $urlsByPlatform['x'] ?? [],
        ];

        try {
            $response = Http::connectTimeout(10)->timeout($this->timeout)->post($url, $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('AI Microservice error on scrapePostComments', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to retrieve post comments from AI microservice.',
            ];
        } catch (\Exception $e) {
            Log::error('AI Microservice exception on scrapePostComments: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
