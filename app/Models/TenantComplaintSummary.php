<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantComplaintSummary extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'summary',
        'recommended_solutions',
        'total_complaints_analyzed',
        'last_complaint_id',
    ];

    protected $casts = [
        'recommended_solutions' => 'array',
        'total_complaints_analyzed' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lastComplaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'last_complaint_id');
    }
}
