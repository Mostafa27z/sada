<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TrendService
{
    public function __construct(
        protected ApifyScraperService $apifyService,
        protected GeminiAnalyticsService $geminiService
    ) {}

    /**
     * Run the end-to-end trend discovery pipeline for a topic/industry.
     */
    public function runPipeline(
        string $topic,
        int $limit = 50,
        array $platforms = ['facebook', 'instagram', 'tiktok', 'twitter']
    ): array {
        // 1. Generate search queries and hashtags via Gemini
        $queryData = $this->geminiService->generateIndustrySearchQueries($topic);
        $searchTerms = $queryData['all_queries'] ?? [$topic];

        $aggregatedData = [];

        // 2. Scrape platforms dynamically based on user selection and limits
        if (in_array('facebook', $platforms)) {
            try {
                $fbPosts = $this->apifyService->fetchFacebookPostsTrending($searchTerms, $limit);
                $aggregatedData = array_merge($aggregatedData, $fbPosts);
            } catch (\Exception $e) {
                Log::error("TrendService Facebook scraping error: " . $e->getMessage());
            }
        }

        if (in_array('instagram', $platforms)) {
            try {
                $igPosts = $this->apifyService->fetchInstagramPostsTrending($searchTerms, $limit);
                $aggregatedData = array_merge($aggregatedData, $igPosts);
            } catch (\Exception $e) {
                Log::error("TrendService Instagram scraping error: " . $e->getMessage());
            }
        }

        if (in_array('tiktok', $platforms)) {
            try {
                $tiktokPosts = $this->apifyService->fetchTiktokTrending($searchTerms, $limit);
                $aggregatedData = array_merge($aggregatedData, $tiktokPosts);
            } catch (\Exception $e) {
                Log::error("TrendService TikTok scraping error: " . $e->getMessage());
            }
        }

        if (in_array('twitter', $platforms)) {
            try {
                $twitterTrends = $this->apifyService->fetchTwitterTrends(['SA', 'EG'], min(50, $limit));
                $aggregatedData = array_merge($aggregatedData, $twitterTrends);
            } catch (\Exception $e) {
                Log::error("TrendService Twitter scraping error: " . $e->getMessage());
            }
        }

        // 3. Cluster and analyze with Gemini
        $analysisResult = [];
        if (!empty($aggregatedData)) {
            $analysisResult = $this->geminiService->analyzeIndustryTrends($topic, $aggregatedData);
        }

        return [
            'keywords' => $queryData['keywords'] ?? [$topic],
            'hashtags' => $queryData['hashtags'] ?? [],
            'raw_data_count' => count($aggregatedData),
            'trends_count' => $analysisResult['trends_count'] ?? 0,
            'trends_analysis' => $analysisResult['trends_analysis'] ?? [],
        ];
    }
}
