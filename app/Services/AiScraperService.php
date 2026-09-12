<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AiScraperService
{
    public function __construct(
        protected ApifyScraperService $apifyService,
        protected GeminiAnalyticsService $geminiService
    ) {}

    /**
     * Scrape posts and generate sentiment analytics by keywords across platforms natively.
     */
    public function scrapeByKeywords(array $keywords, array $platforms = ['instagram', 'facebook', 'x', 'tiktok'], ?string $country = null): array
    {
        if (empty($keywords)) {
            return [
                'status' => 'error',
                'message' => 'No keywords provided.',
            ];
        }

        $instaPosts = [];
        $fbPosts = [];
        $xPosts = [];
        $tiktokPosts = [];

        try {
            if (in_array('instagram', $platforms)) {
                $instaPosts = $this->apifyService->fetchInstagramByKeywords($keywords, 10, $country);
            }
            if (in_array('facebook', $platforms)) {
                $fbPosts = $this->apifyService->fetchFacebookByKeywords($keywords, 10, $country);
            }
            if (in_array('x', $platforms)) {
                $xPosts = $this->apifyService->fetchXByKeywords($keywords, 10, $country);
            }
            if (in_array('tiktok', $platforms)) {
                $tiktokPosts = $this->apifyService->fetchTiktokByKeywords($keywords, 10, $country);
            }

            $allPosts = array_merge($instaPosts, $fbPosts, $xPosts, $tiktokPosts);

            if (empty($allPosts)) {
                return [
                    'status' => 'error',
                    'message' => 'No posts found for the specified keywords.',
                ];
            }

            // Run Gemini sentiment analysis on fetched posts
            $analyticsJson = $this->geminiService->analyzeSentiment($instaPosts, $fbPosts, $xPosts, $tiktokPosts);

            return [
                'status' => 'success',
                'query_keywords' => $keywords,
                'total_posts_count' => count($allPosts),
                'posts_by_platform' => [
                    'instagram_posts' => $instaPosts,
                    'facebook_posts' => $fbPosts,
                    'x_posts' => $xPosts,
                    'tiktok_posts' => $tiktokPosts,
                ],
                'analytics' => $analyticsJson,
            ];
        } catch (\Exception $e) {
            Log::error('Native AiScraperService exception on scrapeByKeywords: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Scrape post comments and sentiment analytics by URLs natively.
     */
    public function scrapePostComments(array $urlsByPlatform): array
    {
        $instaUrls = $urlsByPlatform['insta_urls'] ?? $urlsByPlatform['instagram'] ?? [];
        $fbUrls = $urlsByPlatform['facebook_urls'] ?? $urlsByPlatform['facebook'] ?? [];
        $tiktokUrls = $urlsByPlatform['tiktok_urls'] ?? $urlsByPlatform['tiktok'] ?? [];
        $twitterUrls = $urlsByPlatform['twitter_urls'] ?? $urlsByPlatform['twitter'] ?? $urlsByPlatform['x'] ?? [];

        try {
            $instaComments = !empty($instaUrls) ? $this->apifyService->fetchInstagramComments($instaUrls) : [];
            $fbComments = !empty($fbUrls) ? $this->apifyService->fetchFacebookComments($fbUrls) : [];
            $tiktokComments = !empty($tiktokUrls) ? $this->apifyService->fetchTiktokComments($tiktokUrls) : [];
            $twitterComments = !empty($twitterUrls) ? $this->apifyService->fetchTwitterComments($twitterUrls) : [];

            $allComments = array_merge($instaComments, $fbComments, $tiktokComments, $twitterComments);

            if (empty($allComments)) {
                return [
                    'status' => 'error',
                    'message' => 'No comments found for the provided post URLs.',
                ];
            }

            // Run Gemini sentiment analysis on fetched comments
            $analyticsJson = $this->geminiService->analyzeSentiment($instaComments, $fbComments, $twitterComments, $tiktokComments);

            return [
                'status' => 'success',
                'total_comments_count' => count($allComments),
                'comments_by_platform' => [
                    'instagram_comments' => $instaComments,
                    'facebook_comments' => $fbComments,
                    'tiktok_comments' => $tiktokComments,
                    'twitter_comments' => $twitterComments,
                ],
                'analytics' => $analyticsJson,
            ];
        } catch (\Exception $e) {
            Log::error('Native AiScraperService exception on scrapePostComments: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
