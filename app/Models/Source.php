<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Source extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'type',
        'url',
        'country',
        'language',
        'category',
        'status',
        'configuration',
        'last_synced_at',
        'last_status',
        'created_by',
    ];

    protected $casts = [
        'configuration' => 'array',
        'last_synced_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->slug) {
                $model->slug = Str::slug($model->name) . '-' . Str::random(5);
            }

            if (!$model->tenant_id && TenantContext::check() && !TenantContext::isBypassed()) {
                $model->tenant_id = TenantContext::getTenantId();
            }
        });
    }

    /**
     * Scope query to accessible sources: Global (tenant_id = null) + Active Tenant sources.
     */
    public function scopeAccessible(Builder $query): Builder
    {
        if (TenantContext::isBypassed()) {
            return $query;
        }

        $tenantId = TenantContext::getTenantId();

        if ($tenantId) {
            return $query->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            });
        }

        return $query->whereNull('tenant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
