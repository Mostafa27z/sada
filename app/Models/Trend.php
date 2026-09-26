<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trend extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'created_by',
        'topic',
        'status',
        'limit',
        'platforms',
        'country',
        'date_from',
        'date_to',
        'keywords',
        'hashtags',
        'raw_data_count',
        'trends_count',
        'trends_analysis',
        'error_message',
    ];

    protected $casts = [
        'limit' => 'integer',
        'platforms' => 'array',
        'keywords' => 'array',
        'hashtags' => 'array',
        'raw_data_count' => 'integer',
        'trends_count' => 'integer',
        'trends_analysis' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
