<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GupshupWebhookController extends Controller
{
    /**
     * Handle incoming Gupshup webhook callbacks (inbound messages, verification probes, and delivery status receipts).
     */
    public function handle(Request $request, string $token): JsonResponse
    {
        $setting = WhatsappSetting::withoutGlobalScopes()
            ->where('webhook_token', $token)
            ->first();

        if (!$setting) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid webhook token',
            ], 404);
        }

        // Update last webhook activity timestamp so tenant knows webhook is alive
        $setting->update(['last_webhook_received_at' => now()]);

        $payload = $request->all();

        // Gupshup verification / health ping (GET or empty POST or user-event sandbox check)
        if ($request->isMethod('get') || empty($payload)) {
            return response()->json(['status' => 'ok', 'verified' => true], 200);
        }

        $eventType = $payload['type'] ?? null;
        $innerPayload = $payload['payload'] ?? [];

        if ($eventType === 'message') {
            $this->handleInboundMessage($setting, $innerPayload, $payload);
        } elseif ($eventType === 'message-event') {
            $this->handleMessageStatusEvent($setting, $innerPayload);
        }

        return response()->json(['status' => 'success'], 200);
    }

    protected function handleInboundMessage(WhatsappSetting $setting, array $innerPayload, array $fullPayload): void
    {
        $rawSourcePhone = $innerPayload['source']
            ?? ($innerPayload['sender']['phone'] ?? null);

        if (!$rawSourcePhone) {
            return;
        }

        $normalizedPhone = PhoneNormalizer::toE164((string) $rawSourcePhone, $setting->default_country_code ?? '966');
        if (!$normalizedPhone) {
            return;
        }

        $senderName = $innerPayload['sender']['name'] ?? null;
        $gupshupMsgId = $innerPayload['id'] ?? ($innerPayload['gsId'] ?? null);
        $msgType = $innerPayload['type'] ?? 'text';
        $msgContent = $innerPayload['payload'] ?? [];

        // Avoid duplicate processing if Gupshup retries same wamid
        if ($gupshupMsgId && WhatsappMessage::withoutGlobalScopes()->where('gupshup_message_id', $gupshupMsgId)->exists()) {
            return;
        }

        $bodyText = match ($msgType) {
            'text' => $msgContent['text'] ?? '',
            'button_reply' => $msgContent['title'] ?? ($msgContent['text'] ?? '[رد تفاعلي]'),
            'list_reply' => $msgContent['title'] ?? ($msgContent['text'] ?? '[اختيار من القائمة]'),
            'image' => $msgContent['caption'] ?? '📷 [صورة مرفقة]',
            'video' => $msgContent['caption'] ?? '🎥 [فيديو مرفق]',
            'audio', 'voice' => '🎤 [رسالة صوتية]',
            'file', 'document' => $msgContent['caption'] ?? ('📄 [ملف: ' . ($msgContent['name'] ?? 'مستند') . ']'),
            'location' => '📍 [موقع جغرافي]',
            default => is_string($msgContent['text'] ?? null) ? $msgContent['text'] : '[رسالة واردة عبر واتساب]',
        };

        $mediaUrl = $msgContent['url'] ?? null;

        // Match with latest complaint sharing this normalized phone in this tenant
        $latestComplaint = Complaint::withoutGlobalScopes()
            ->where('tenant_id', $setting->tenant_id)
            ->where('normalized_phone', $normalizedPhone)
            ->latest()
            ->first();

        $conversation = WhatsappConversation::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $setting->tenant_id,
                'normalized_phone' => $normalizedPhone,
            ],
            [
                'raw_phone' => $rawSourcePhone,
                'contact_name' => $latestComplaint?->name ?: ($senderName ?: 'عميل واتساب'),
                'unread_count' => 0,
            ]
        );

        $conversation->update([
            'contact_name' => $latestComplaint?->name ?: ($senderName ?: $conversation->contact_name),
            'last_message_preview' => Str::limit($bodyText, 120),
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'unread_count' => $conversation->unread_count + 1,
        ]);

        WhatsappMessage::withoutGlobalScopes()->create([
            'tenant_id' => $setting->tenant_id,
            'conversation_id' => $conversation->id,
            'complaint_id' => $latestComplaint?->id,
            'gupshup_message_id' => $gupshupMsgId,
            'direction' => 'inbound',
            'message_type' => $msgType,
            'body' => $bodyText,
            'media_url' => $mediaUrl,
            'status' => 'delivered',
            'metadata' => $fullPayload,
        ]);

        // Also record inbound customer reply on the latest active complaint's timeline
        if ($latestComplaint) {
            $latestComplaint->activities()->create([
                'tenant_id' => $setting->tenant_id,
                'user_id' => null,
                'type' => 'note',
                'comment' => "💬 رد وارد من المستفيد عبر واتساب:\n{$bodyText}",
                'is_visible_to_customer' => false,
                'metadata' => [
                    'channel' => 'whatsapp_inbound',
                    'gupshup_message_id' => $gupshupMsgId,
                    'phone' => $normalizedPhone,
                ],
            ]);
        }
    }

    protected function handleMessageStatusEvent(WhatsappSetting $setting, array $innerPayload): void
    {
        $gsId = $innerPayload['gsId'] ?? null;
        $waId = $innerPayload['id'] ?? null;
        $eventStatus = strtolower((string) ($innerPayload['type'] ?? ''));

        if (!$gsId && !$waId) {
            return;
        }

        $mappedStatus = match ($eventStatus) {
            'enqueued', 'submitted', 'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            default => null,
        };

        if (!$mappedStatus) {
            return;
        }

        $message = WhatsappMessage::withoutGlobalScopes()
            ->where('tenant_id', $setting->tenant_id)
            ->where(function ($q) use ($gsId, $waId) {
                if ($gsId) {
                    $q->where('gupshup_message_id', $gsId);
                }
                if ($waId) {
                    $q->orWhere('gupshup_message_id', $waId);
                }
            })
            ->latest()
            ->first();

        if ($message) {
            $errorReason = $eventStatus === 'failed'
                ? ($innerPayload['payload']['reason'] ?? 'فشل التسليم من مزود الخدمة Gupshup')
                : $message->error_message;

            $message->update([
                'status' => $mappedStatus,
                'error_message' => $errorReason,
            ]);
        }
    }
}

