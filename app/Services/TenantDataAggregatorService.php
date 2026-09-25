<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ChatRoom;
use App\Models\Comment;
use App\Models\Complaint;
use App\Models\Keyword;
use App\Models\Tenant;
use App\Models\TenantComplaintSummary;
use App\Models\Trend;
use Illuminate\Support\Facades\Log;

class TenantDataAggregatorService
{
    /**
     * Detect the user query intent to decide which data is actually relevant.
     *
     * @param string $query
     * @return string 'copywriting' | 'reputation_complaints' | 'trends_market' | 'general'
     */
    public function detectQueryIntent(string $query): string
    {
        $lower = mb_strtolower($query);

        $reputationKeywords = ['شكاوى', 'شكوى', 'مشاكل', 'مشكلة', 'تقييمات', 'تقييم', 'استياء', 'غاضب', 'تأخير', 'خدمة العملاء', 'رضا العملاء', 'complaint', 'negative', 'issue', 'bad service'];
        foreach ($reputationKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                return 'reputation_complaints';
            }
        }

        $copywritingKeywords = ['منشور', 'بوست', 'اكتب', 'صياغة', 'إعلان', 'تسويق ل', 'تسويق', 'حملة', 'محتوى', 'تغريدة', 'سكريبت', 'عنوان', 'رسالة تسويقية', 'post', 'write', 'copy', 'ad', 'campaign', 'script'];
        foreach ($copywritingKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                return 'copywriting';
            }
        }

        $trendKeywords = ['تريند', 'ترند', 'المنافسين', 'المنافس', 'السوق', 'رائج', 'فكرة جديدة', 'viral', 'trend', 'market', 'competitor'];
        foreach ($trendKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                return 'trends_market';
            }
        }

        return 'general';
    }

    /**
     * Gather context-aware marketing & operational data for a tenant tailored to the user's intent.
     *
     * @param Tenant $tenant
     * @param ChatRoom $room
     * @param string $userQuery
     * @return array
     */
    public function getTenantMarketingContext(Tenant $tenant, ChatRoom $room, string $userQuery = ''): array
    {
        $tenantId = $tenant->id;
        $intent = $this->detectQueryIntent($userQuery);

        $context = [
            'company_name' => $tenant->name,
            'detected_intent' => $intent,
        ];

        // 1. Complaints & Customer Sentiment - ONLY included when relevant (reputation or general)
        if ($intent === 'reputation_complaints' || $intent === 'general') {
            try {
                $complaintSummary = TenantComplaintSummary::where('tenant_id', $tenantId)->first();
                $recentComplaints = Complaint::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->latest()
                    ->take(5)
                    ->get(['name', 'rate', 'priority', 'opinion', 'status', 'created_at'])
                    ->map(fn ($c) => [
                        'name' => $c->name,
                        'rate' => $c->rate,
                        'priority' => $c->priority,
                        'opinion' => $c->opinion,
                    ])
                    ->toArray();

                $avgRating = Complaint::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->avg('rate');

                $context['average_rating'] = $avgRating ? round($avgRating, 1) : null;
                $context['complaint_executive_summary'] = $complaintSummary?->summary;
                $context['recent_complaints'] = $recentComplaints;
            } catch (\Throwable $e) {
                Log::warning("TenantDataAggregatorService complaints query: " . $e->getMessage());
            }
        }

        // 2. Tracked Keywords - included for copywriting, trends, or general
        if ($intent === 'copywriting' || $intent === 'trends_market' || $intent === 'general') {
            try {
                $keywords = Keyword::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->take(10)
                    ->pluck('name')
                    ->toArray();
                $context['tracked_keywords'] = $keywords;
            } catch (\Throwable $e) {
                Log::warning("TenantDataAggregatorService keywords query: " . $e->getMessage());
            }
        }

        // 3. Social Media Comments & Mentions - included for reputation or general
        if ($intent === 'reputation_complaints' || $intent === 'general') {
            try {
                $recentComments = Comment::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->latest('comment_created_at')
                    ->take(5)
                    ->get(['comment_text', 'sentiment', 'platform'])
                    ->map(fn ($cm) => [
                        'platform' => $cm->platform,
                        'sentiment' => $cm->sentiment,
                        'text' => $cm->comment_text,
                    ])
                    ->toArray();
                $context['social_comments'] = $recentComments;
            } catch (\Throwable $e) {
                Log::warning("TenantDataAggregatorService comments query: " . $e->getMessage());
            }
        }

        // 4. Topic Trends & Industry Insights - included for copywriting, trends, or general
        if ($intent === 'copywriting' || $intent === 'trends_market' || $intent === 'general') {
            try {
                $recentTrends = Trend::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->latest()
                    ->take(3)
                    ->get(['topic', 'trends_analysis'])
                    ->map(fn ($t) => [
                        'topic' => $t->topic,
                        'sentiment' => $t->trends_analysis['overall_sentiment'] ?? null,
                    ])
                    ->toArray();
                $context['market_trends'] = $recentTrends;
            } catch (\Throwable $e) {
                Log::warning("TenantDataAggregatorService trends query: " . $e->getMessage());
            }
        }

        // 5. Recent News/Articles - included for trends_market
        if ($intent === 'trends_market') {
            try {
                $recentArticles = Article::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->latest('published_at')
                    ->take(3)
                    ->pluck('title')
                    ->toArray();
                $context['recent_articles'] = $recentArticles;
            } catch (\Throwable $e) {
                Log::warning("TenantDataAggregatorService articles query: " . $e->getMessage());
            }
        }

        // 6. Recent Chat History in this Room (up to 6 previous messages)
        try {
            $chatHistory = $room->messages()
                ->with('user:id,name')
                ->latest()
                ->take(6)
                ->get()
                ->reverse()
                ->map(fn ($m) => [
                    'sender' => $m->sender_type === 'ai' ? $room->ai_consultant_name : ($m->user?->name ?? 'عضو الفريق'),
                    'message' => $m->message,
                ])
                ->values()
                ->toArray();
            $context['chat_history'] = $chatHistory;
        } catch (\Throwable $e) {
            Log::warning("TenantDataAggregatorService chatHistory query: " . $e->getMessage());
        }

        return $context;
    }
}
