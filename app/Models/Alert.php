<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    use HasFactory, BelongsToTenant;

    const STATUS_UNREAD = 'unread';
    const STATUS_READ = 'read';
    const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'tenant_id',
        'alert_rule_id',
        'article_id',
        'title',
        'message',
        'type',
        'status',
        'read_at',
        'resolved_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
