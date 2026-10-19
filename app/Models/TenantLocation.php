<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantLocation extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'tenant_locations';

    protected $fillable = [
        'tenant_id',
        'business_name',
        'address',
        'latitude',
        'longitude',
        'maps_url',
        'google_place_id',
        'google_cid',
        'provider',
        'gbp_account_id',
        'gbp_location_id',
        'gbp_access_token',
        'gbp_refresh_token',
        'gbp_token_expires_at',
        'is_verified_owner',
        'can_reply',
        'sync_status',
        'last_synced_at',
        'last_error_message',
        'total_reviews',
        'average_rating',
        'rating_distribution',
        'unanswered_reviews',
        'ai_summary',
        'ai_recommendations',
        'ai_analyzed_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'is_verified_owner' => 'boolean',
        'can_reply' => 'boolean',
        'gbp_token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'ai_analyzed_at' => 'datetime',
        'rating_distribution' => 'array',
        'ai_recommendations' => 'array',
        'total_reviews' => 'integer',
        'average_rating' => 'float',
        'unanswered_reviews' => 'integer',
    ];

    /**
     * Reviews associated with this location.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(LocationReview::class, 'tenant_location_id');
    }
}
