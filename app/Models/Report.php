<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasFactory, BelongsToTenant;

    const STATUS_PENDING = 'pending';
    const STATUS_GENERATING = 'generating';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    const TYPE_DAILY_SUMMARY = 'daily_summary';
    const TYPE_CAMPAIGN_AUTO = 'campaign_auto';
    const TYPE_MONITORING_AUTO = 'monitoring_auto';
    const TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'format',
        'parameters',
        'file_path',
        'status',
        'created_by',
    ];

    protected $casts = [
        'parameters' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
