<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'title' => $this->title,
            'description' => $this->description,
            'is_ai_enabled' => $this->is_ai_enabled,
            'ai_consultant_name' => $this->ai_consultant_name,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'email' => $this->creator->email,
            ]),
            'members_count' => $this->users()->count(),
            'members' => $this->whenLoaded('users', fn () => $this->users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot?->role ?? 'member',
            ])),
            'latest_message' => $this->whenLoaded('messages', fn () => $this->messages->first() ? [
                'id' => $this->messages->first()->id,
                'sender_type' => $this->messages->first()->sender_type,
                'message' => $this->messages->first()->message,
                'created_at' => $this->messages->first()->created_at?->toISOString(),
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
