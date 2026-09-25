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

        // 2. Tracked Keywords
        $keywords = Keyword::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->take(15)
            ->pluck('keyword')
            ->toArray();

        // 3. Social Media Comments & Mentions
        $recentComments = Comment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest()
            ->take(10)
            ->get(['text', 'sentiment', 'platform'])
            ->map(fn ($cm) => [
                'platform' => $cm->platform,
                'sentiment' => $cm->sentiment,
                'text' => $cm->text,
            ])
            ->toArray();

        // 4. Topic Trends & Industry Insights
        $recentTrends = Trend::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest()
            ->take(5)
            ->get(['topic', 'sentiment_distribution', 'overall_sentiment', 'confidence_score'])
            ->map(fn ($t) => [
                'topic' => $t->topic,
                'sentiment' => $t->overall_sentiment,
            ])
            ->toArray();

        // 5. Recent News/Articles
        $recentArticles = Article::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('published_at')
            ->take(5)
            ->pluck('title')
            ->toArray();

        // 6. Recent Chat History in this Room (up to 8 previous messages)
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
