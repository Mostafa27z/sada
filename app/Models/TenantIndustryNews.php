<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantIndustryNews extends Model
{
    use HasFactory, BelongsToTenant;

    const STATUS_UNREAD = 'unread';
    const STATUS_ACTED = 'acted';
    const STATUS_DISMISSED = 'dismissed';

    protected $table = 'tenant_industry_news';

    protected $fillable = [
        'tenant_id',
        'batch_date',
        'rank',
        'title',
        'summary',
        'source_name',
        'source_url',
        'published_at',
        'category',
        'importance_score',
        'why_it_matters',
        'suggested_actions',
        'status',
    ];

    protected $casts = [
        'batch_date' => 'date:Y-m-d',
        'published_at' => 'datetime',
        'importance_score' => 'decimal:2',
        'suggested_actions' => 'array',
        'rank' => 'integer',
    ];

    /**
     * Scope query to items from today (KSA time / current day).
     */
    public function scopeToday(Builder $query): Builder
    {
        $todayKsa = Carbon::now('Asia/Riyadh')->toDateString();
        return $query->where('batch_date', $todayKsa);
    }

    /**
     * Scope query for a specific batch date.
     */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('batch_date', $date);
    }

    /**
     * Scope query ordered by top rank.
     */
    public function scopeTopRanked(Builder $query): Builder
    {
        return $query->orderBy('rank', 'asc');
    }

    /**
     * Relation to tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
