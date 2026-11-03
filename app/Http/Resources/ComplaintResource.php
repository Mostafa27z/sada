<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tracking_number' => $this->tracking_number ?? "{$this->id}",
            'tenant_id' => $this->tenant_id,
            'tenant_location_id' => $this->tenant_location_id,
            'location' => $this->location ? [
                'id' => $this->location->id,
                'business_name' => $this->location->business_name,
                'address' => $this->location->address,
            ] : null,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'normalized_phone' => $this->normalized_phone,
            'whatsapp_unread_count' => $this->resolveWhatsappUnreadCount(),
            'same_phone_complaints_count' => $this->resolveSamePhoneComplaintsCount(),
            'opinion' => $this->opinion,
            'rate' => $this->rate,
            'priority' => $this->priority ?? 'neutral',
            'ai_recommendation' => $this->ai_recommendation,
            'status' => $this->stage ?? $this->status ?? 'new',
            'stage' => $this->stage ?? 'new',
            'assigned_to' => $this->assigned_to,
            'assigned_user' => $this->assignedUser ? [
                'id' => $this->assignedUser->id,
                'name' => $this->assignedUser->name,
                'email' => $this->assignedUser->email,
                'avatar' => $this->assignedUser->avatar,
                'role' => $this->assignedUser->currentRole()?->name ?? 'ممثل خدمة العملاء والدعم الفني',
                'role_slug' => $this->assignedUser->currentRole()?->slug ?? 'customer_support',
            ] : null,
            'first_responded_at' => $this->first_responded_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'is_analyzed' => (bool) $this->is_analyzed,
            'analyzed_at' => $this->analyzed_at?->toISOString(),
            'activities' => ComplaintActivityResource::collection($this->whenLoaded('activities')),
            'notes_count' => $this->relationLoaded('activities') ? $this->activities->whereIn('type', ['note', 'call_log'])->count() : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    protected function resolveWhatsappUnreadCount(): int
    {
        if (empty($this->normalized_phone) && empty($this->phone)) {
            return 0;
        }
        try {
            $norm = $this->normalized_phone ?: \App\Support\PhoneNormalizer::toE164($this->phone);
            if (!$norm) {
                return 0;
            }
            return (int) \App\Models\WhatsappConversation::withoutGlobalScopes()
                ->where('tenant_id', $this->tenant_id)
                ->where('normalized_phone', $norm)
                ->value('unread_count');
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function resolveSamePhoneComplaintsCount(): int
    {
        if (empty($this->phone)) {
            return 0;
        }
        try {
            if (!empty($this->normalized_phone)) {
                return (int) \App\Models\Complaint::withoutGlobalScopes()
                    ->where('tenant_id', $this->tenant_id)
                    ->where('normalized_phone', $this->normalized_phone)
                    ->count();
            }
            return (int) \App\Models\Complaint::withoutGlobalScopes()
                ->where('tenant_id', $this->tenant_id)
                ->where('phone', $this->phone)
                ->count();
        } catch (\Throwable) {
            return 1;
        }
    }
}
