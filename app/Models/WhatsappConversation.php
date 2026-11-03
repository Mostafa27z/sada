<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConversation extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'normalized_phone',
        'raw_phone',
        'contact_name',
        'last_message_preview',
        'last_message_at',
        'last_inbound_at',
        'unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class, 'conversation_id')->orderBy('created_at', 'asc');
    }

    /**
     * Check if the 24-hour Meta customer service session window is currently active.
     */
    public function isSessionWindowOpen(): bool
    {
        if (!$this->last_inbound_at) {
            return false;
        }

        return $this->last_inbound_at->diffInHours(now()) < 24;
    }
}

