<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chat_room_id' => $this->chat_room_id,
            'sender_type' => $this->sender_type, // 'user' or 'ai'
            'user' => $this->sender_type === 'user' && $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'message' => $this->message,
            'thinking_steps' => $this->thinking_steps ?? [],
            'metadata' => $this->metadata ?? null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
