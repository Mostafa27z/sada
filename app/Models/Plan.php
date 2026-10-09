<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'billing_interval',
        'max_users',
        'max_keywords',
        'max_sources',
        'max_articles',
        'max_api_requests',
        'max_campaigns',
        'max_articles_per_campaign',
        'features',
        'has_news',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'max_users' => 'integer',
        'max_keywords' => 'integer',
        'max_sources' => 'integer',
        'max_articles' => 'integer',
        'max_api_requests' => 'integer',
        'max_campaigns' => 'integer',
        'max_articles_per_campaign' => 'integer',
        'features' => 'array',
        'has_news' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function planRequests(): HasMany
    {
        return $this->hasMany(PlanRequest::class);
    }

    public function hasNews(): bool
    {
        return (bool) $this->has_news;
    }
}
