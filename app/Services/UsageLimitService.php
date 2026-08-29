<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Tenant;
use App\Support\TenantContext;

class UsageLimitService
{
    /**
     * Get active plan for tenant workspace (or fallback default limits).
     */
    public function getTenantPlan(Tenant $tenant): ?Plan
    {
        return TenantContext::withoutTenancy(function () use ($tenant) {
            $subscription = $tenant->subscription;

            if ($subscription && $subscription->isActive()) {
                return $subscription->plan;
            }

            if ($tenant->plan_id) {
                return Plan::find($tenant->plan_id);
            }

            // Fall back to lowest active plan
            return Plan::where('is_active', true)->orderBy('price', 'asc')->first();
        });
    }

    /**
     * Get usage statistics envelope for tenant.
     */
    public function getUsage(Tenant $tenant): array
    {
        $plan = $this->getTenantPlan($tenant);

        // Count current users in tenant
        $usersCount = TenantContext::withoutTenancy(fn () => $tenant->users()->count());
        $maxUsers = $plan?->max_users ?? 5;

        // Real database counts for keywords and sources
        $keywordsCount = TenantContext::withoutTenancy(fn () => \App\Models\Keyword::where('tenant_id', $tenant->id)->count());
        $maxKeywords = $plan?->max_keywords ?? 20;

        $sourcesCount = TenantContext::withoutTenancy(fn () => \App\Models\Source::where('tenant_id', $tenant->id)->count());
        $maxSources = $plan?->max_sources ?? 50;

        $articlesCount = TenantContext::withoutTenancy(fn () => \App\Models\Article::where('tenant_id', $tenant->id)->count());
        $maxArticles = $plan?->max_articles ?? 10000;

        return [
            'plan' => [
                'name' => $plan?->name ?? 'الباقة المجانية',
                'slug' => $plan?->slug ?? 'free-trial',
            ],
            'users' => [
                'used' => $usersCount,
                'limit' => $maxUsers,
                'remaining' => max(0, $maxUsers - $usersCount),
            ],
            'keywords' => [
                'used' => $keywordsCount,
                'limit' => $maxKeywords,
                'remaining' => max(0, $maxKeywords - $keywordsCount),
            ],
            'sources' => [
                'used' => $sourcesCount,
                'limit' => $maxSources,
                'remaining' => max(0, $maxSources - $sourcesCount),
            ],
            'articles' => [
                'used' => $articlesCount,
                'limit' => $maxArticles,
                'remaining' => max(0, $maxArticles - $articlesCount),
            ],
        ];
    }

    public function canCreateUser(Tenant $tenant): bool
    {
        $usage = $this->getUsage($tenant);
        return $usage['users']['remaining'] > 0;
    }

    public function canCreateKeyword(Tenant $tenant): bool
    {
        $usage = $this->getUsage($tenant);
        return $usage['keywords']['remaining'] > 0;
    }

    public function canCreateSource(Tenant $tenant): bool
    {
        $usage = $this->getUsage($tenant);
        return $usage['sources']['remaining'] > 0;
    }

    public function canStoreArticle(Tenant $tenant): bool
    {
        $usage = $this->getUsage($tenant);
        return $usage['articles']['remaining'] > 0;
    }
}
