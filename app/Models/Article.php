<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Article extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'source_id',
        'external_id',
        'title',
        'slug',
        'content',
        'summary',
        'url',
        'image_url',
        'author',
        'language',
        'country',
        'category',
        'published_at',
        'sentiment',
        'sentiment_score',
        'ai_metadata',
        'raw_data',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'sentiment_score' => 'decimal:4',
        'ai_metadata' => 'array',
        'raw_data' => 'array',
    ];

    protected $appends = [
        'platform',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->slug) {
                $model->slug = Str::slug($model->title) . '-' . Str::random(5);
            }
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'article_keyword')
            ->withPivot(['matched_terms', 'relevance_score']);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_articles');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function getPlatformAttribute(): string
    {
        $rawPlat = strtolower((string)($this->raw_data['platform'] ?? ($this->raw_data['raw_post']['platform'] ?? ($this->source?->type ?? ''))));
        if (empty($rawPlat) || $rawPlat === 'web' || $rawPlat === 'social') {
            if (!empty($this->author)) {
                $authorLower = strtolower($this->author);
                if (str_contains($authorLower, 'facebook') || str_contains($authorLower, 'فيسبوك')) return 'facebook';
                if (str_contains($authorLower, 'instagram') || str_contains($authorLower, 'انستغرام') || str_contains($authorLower, 'إنستغرام')) return 'instagram';
                if (str_contains($authorLower, 'tiktok') || str_contains($authorLower, 'تيك')) return 'tiktok';
                if (str_contains($authorLower, 'twitter') || str_contains($authorLower, 'تويتر') || $authorLower === 'x') return 'x';
            }
            if (!empty($this->url)) {
                if (str_contains($this->url, 'facebook.com') || str_contains($this->url, 'fb.com') || str_contains($this->url, 'fb.watch')) {
                    return 'facebook';
                } elseif (str_contains($this->url, 'instagram.com')) {
                    return 'instagram';
                } elseif (str_contains($this->url, 'tiktok.com')) {
                    return 'tiktok';
                } elseif (str_contains($this->url, 'twitter.com') || str_contains($this->url, 'x.com')) {
                    return 'x';
                }
            }
        }
        if (str_contains($rawPlat, 'x') || str_contains($rawPlat, 'twitter')) {
            return 'x';
        } elseif (str_contains($rawPlat, 'insta')) {
            return 'instagram';
        } elseif (str_contains($rawPlat, 'face')) {
            return 'facebook';
        } elseif (str_contains($rawPlat, 'tik')) {
            return 'tiktok';
        }
        return 'web';
    }
}
