<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    use HasFactory, BelongsToTenant;

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_ACTIVE = 'active';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'link',
        'platform',
        'country',
        'date_from',
        'date_to',
        'keywords',
        'comments_limit',
        'color',
        'status',
        'error_message',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'collection_articles')
            ->withPivot('created_at');
    }
}
