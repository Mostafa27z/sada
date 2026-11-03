<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WhatsappSetting extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'app_id',
        'app_name',
        'api_key',
        'source_phone',
        'default_country_code',
        'webhook_token',
        'is_active',
        'auto_send_on_stage_change',
        'auto_send_on_new_complaint',
        'last_webhook_received_at',
        'hidden_gupshup_templates',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_send_on_stage_change' => 'boolean',
        'auto_send_on_new_complaint' => 'boolean',
        'last_webhook_received_at' => 'datetime',
        'hidden_gupshup_templates' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (WhatsappSetting $setting) {
            if (empty($setting->webhook_token)) {
                $setting->webhook_token = self::generateUniqueToken();
            }
        });
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(40);
        } while (self::withoutGlobalScopes()->where('webhook_token', $token)->exists());

        return $token;
    }

    public static function forTenant(int $tenantId): self
    {
        return self::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'default_country_code' => '966',
                'webhook_token' => self::generateUniqueToken(),
                'is_active' => false,
                'auto_send_on_stage_change' => true,
                'auto_send_on_new_complaint' => false,
            ]
        );
    }

    public function getWebhookUrlAttribute(): string
    {
        $baseUrl = rtrim(config('app.url', request()->getSchemeAndHttpHost()), '/');
        return "{$baseUrl}/api/v1/webhooks/gupshup/{$this->webhook_token}";
    }
}

