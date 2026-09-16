<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'article_id',
        'parent_id',
        'platform',
        'external_id',
        'parent_external_id',
        'author',
        'comment_text',
        'sentiment',
        'sentiment_score',
        'likes_count',
        'replies_count',
        'comment_created_at',
        'raw_data',
    ];

    protected $casts = [
        'sentiment_score' => 'decimal:4',
        'likes_count' => 'integer',
        'replies_count' => 'integer',
        'comment_created_at' => 'datetime',
        'raw_data' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')->orderBy('comment_created_at', 'asc')->orderBy('created_at', 'asc');
    }

    public function scopeParentsOnly($query)
    {
        return $query->whereNull('parent_id');
    }
}
