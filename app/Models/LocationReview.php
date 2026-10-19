<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationReview extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'location_reviews';

    protected $fillable = [
        'tenant_id',
        'tenant_location_id',
        'google_review_id',
        'source',
        'author_name',
        'author_photo_url',
        'rating',
        'sentiment',
        'sentiment_score',
        'ai_analysis',
        'text',
        'published_at',
        'relative_time_description',
        'likes_count',
        'response_text',
        'response_published_at',
        'is_responded',
    ];

    protected $casts = [
        'rating' => 'integer',
        'sentiment_score' => 'float',
        'likes_count' => 'integer',
        'is_responded' => 'boolean',
        'published_at' => 'datetime',
        'response_published_at' => 'datetime',
    ];

    /**
     * Parent Location.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(TenantLocation::class, 'tenant_location_id');
    }
}
