<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'tenant_location_id',
        'tracking_number',
        'name',
        'email',
        'phone',
        'normalized_phone',
        'opinion',
        'rate',
        'priority',
        'ai_recommendation',
        'status',
        'assigned_to',
        'stage',
        'is_analyzed',
        'analyzed_at',
        'first_responded_at',
        'resolved_at',
    ];

    protected static function booted()
    {
        static::creating(function ($complaint) {
            if (empty($complaint->tracking_number)) {
                $complaint->tracking_number = self::generateTrackingNumber();
            }
        });

        static::saving(function ($complaint) {
            try {
                if (!\Illuminate\Support\Facades\Schema::hasColumn('complaints', 'normalized_phone')) {
                    return;
                }
                if (!empty($complaint->phone)) {
                    $defaultCc = '966';
                    if ($complaint->tenant_id && \Illuminate\Support\Facades\Schema::hasTable('whatsapp_settings')) {
                        $setting = WhatsappSetting::withoutGlobalScopes()
                            ->where('tenant_id', $complaint->tenant_id)
                            ->first(['default_country_code']);
                        if ($setting?->default_country_code) {
                            $defaultCc = $setting->default_country_code;
                        }
                    }
                    $complaint->normalized_phone = \App\Support\PhoneNormalizer::toE164($complaint->phone, $defaultCc);
                } else {
                    $complaint->normalized_phone = null;
                }
            } catch (\Throwable) {
                // Safe fallback if migration has not run yet
            }
        });
    }

    public static function generateTrackingNumber(): string
    {
        $date = date('Ymd');
        $todayCount = self::withoutGlobalScopes()->whereDate('created_at', today())->count();
        $order = str_pad((string)($todayCount + 1), 2, '0', STR_PAD_LEFT);

        do {
            $rand = mt_rand(1000, 9999);
            $trackingNumber = "{$date}_{$order}_{$rand}";
        } while (self::withoutGlobalScopes()->where('tracking_number', $trackingNumber)->exists());

        return $trackingNumber;
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'rate' => 'integer',
        'is_analyzed' => 'boolean',
        'analyzed_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities()
    {
        return $this->hasMany(ComplaintActivity::class)->latest();
    }

    public function notes()
    {
        return $this->hasMany(ComplaintActivity::class)->whereIn('type', ['note', 'call_log'])->latest();
    }

    public function location()
    {
        return $this->belongsTo(TenantLocation::class, 'tenant_location_id')->withoutGlobalScopes();
    }
}
