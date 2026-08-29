<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'trigger_type',
        'keyword_id',
        'sentiment',
        'channels',
        'recipients',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'channels' => 'array',
        'recipients' => 'array',
        'is_active' => 'boolean',
    ];

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
