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

class TenantDataAggregatorService
{
    /**
     * Gather a comprehensive marketing & operational data snapshot for a tenant.
     *
     * @param Tenant $tenant
     * @param ChatRoom $room
     * @return array
     */
    public function getTenantMarketingContext(Tenant $tenant, ChatRoom $room): array
    {
        $tenantId = $tenant->id;

        // 1. Complaints & Customer Sentiment Snapshot
        $complaintSummary = null;
        $recentComplaints = [];
        $avgRating = null;
        try {
            $complaintSummary = TenantComplaintSummary::where('tenant_id', $tenantId)->first();
            $recentComplaints = Complaint::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->latest()
                ->take(10)
                ->get(['name', 'rate', 'priority', 'opinion', 'status', 'created_at'])
                ->map(fn ($c) => [
                    'name' => $c->name,
                    'rate' => $c->rate,
                    'priority' => $c->priority,
                    'opinion' => $c->opinion,
                    'status' => $c->status,
                ])
                ->toArray();

            $avgRating = Complaint::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->avg('rate');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("TenantDataAggregatorService: complaints query failed: " . $e->getMessage());
        }

        // 2. Tracked Keywords (column is 'name')
        $keywords = [];
        try {
            $keywords = Keyword::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->take(15)
                ->pluck('name')
                ->toArray();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("TenantDataAggregatorService: keywords query failed: " . $e->getMessage());
        }

        // 3. Social Media Comments & Mentions (column is 'comment_text')
        $recentComments = [];
        try {
            $recentComments = Comment::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->latest('comment_created_at')
                ->take(10)
                ->get(['comment_text', 'sentiment', 'platform'])
                ->map(fn ($cm) => [
                    'platform' => $cm->platform,
                    'sentiment' => $cm->sentiment,
                    'text' => $cm->comment_text,
                ])
                ->toArray();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("TenantDataAggregatorService: comments query failed: " . $e->getMessage());
        }

        // 4. Topic Trends & Industry Insights
        $recentTrends = [];
        try {
            $recentTrends = Trend::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->latest()
                ->take(5)
                ->get(['topic', 'trends_analysis'])
                ->map(fn ($t) => [
                    'topic' => $t->topic,
                    'sentiment' => $t->trends_analysis['overall_sentiment'] ?? null,
                ])
                ->toArray();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("TenantDataAggregatorService: trends query failed: " . $e->getMessage());
        }

        // 5. Recent News/Articles
        $recentArticles = [];
        try {
            $recentArticles = Article::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->latest('published_at')
                ->take(5)
                ->pluck('title')
                ->toArray();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("TenantDataAggregatorService: articles query failed: " . $e->getMessage());
        }

        // 6. Recent Chat History in this Room (up to 8 previous messages)
        $chatHistory = [];
        try {
            $chatHistory = $room->messages()
                ->with('user:id,name')
                ->latest()
                ->take(8)
                ->get()
                ->reverse()
                ->map(fn ($m) => [
                    'sender' => $m->sender_type === 'ai' ? $room->ai_consultant_name : ($m->user?->name ?? 'عضو الفريق'),
                    'message' => $m->message,
                ])
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("TenantDataAggregatorService: chatHistory query failed: " . $e->getMessage());
        }

        return [
            'company_name' => $tenant->name,
            'average_rating' => $avgRating ? round($avgRating, 1) : null,
            'complaint_executive_summary' => $complaintSummary?->summary,
            'recommended_solutions_history' => $complaintSummary?->recommended_solutions ?? [],
            'recent_complaints' => $recentComplaints,
            'tracked_keywords' => $keywords,
            'social_comments' => $recentComments,
            'market_trends' => $recentTrends,
            'recent_articles' => $recentArticles,
            'chat_history' => $chatHistory,
        ];
    }
}
