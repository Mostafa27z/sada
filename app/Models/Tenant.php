<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';
    const STATUS_TRIAL = 'trial';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'ulid',
        'name',
        'slug',
        'logo',
        'status',
        'plan_id',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->ulid) {
                $model->ulid = (string) Str::ulid();
            }
            if (!$model->slug) {
                $model->slug = Str::slug($model->name) . '-' . Str::random(5);
            }
        });
    }

    /**
     * Relationship: Users belonging to this tenant.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot(['id', 'role_id', 'is_owner'])
            ->withTimestamps();
    }

    /**
     * Relationship: Pivot records for this tenant.
     */
    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Helper: Get owner user of tenant.
     */
    public function owner(): ?User
    {
        return $this->users()->wherePivot('is_owner', true)->first();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE || $this->status === self::STATUS_TRIAL;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
