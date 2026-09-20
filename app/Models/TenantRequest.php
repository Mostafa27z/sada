<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'sector',
        'contact_name',
        'contact_email',
        'contact_phone',
        'requested_plan_id',
        'commercial_register',
        'status',
        'rejection_reason',
        'notes',
        'created_tenant_id',
    ];

    public function requestedPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'requested_plan_id');
    }

    public function createdTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'created_tenant_id');
    }
}
