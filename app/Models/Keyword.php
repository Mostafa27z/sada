<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Keyword extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    const STATUS_ACTIVE = 'active';
    const STATUS_PAUSED = 'paused';
    const STATUS_ARCHIVED = 'archived';

    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    const MATCH_EXACT = 'exact';
    const MATCH_PHRASE = 'phrase';
    const MATCH_CONTAINS = 'contains';
    const MATCH_ADVANCED = 'advanced';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'language',
        'country',
        'priority',
        'status',
        'match_type',
        'configuration',
        'created_by',
    ];

    protected $casts = [
        'configuration' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->slug) {
                $model->slug = Str::slug($model->name) . '-' . Str::random(5);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_keyword')
            ->withPivot(['matched_terms', 'relevance_score'])
            ->withTimestamps();
    }
}
