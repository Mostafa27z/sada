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
}
