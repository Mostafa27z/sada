<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessage extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'complaint_id',
        'sender_user_id',
        'gupshup_message_id',
        'direction',
        'message_type',
        'triggered_by_stage',
        'body',
        'media_url',
        'status',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'complaint_id')->withoutGlobalScopes();
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}

