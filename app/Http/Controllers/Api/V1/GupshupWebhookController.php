<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Support\PhoneNormalizer;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class GupshupWebhookController extends Controller
{
    /**
     * Handle incoming Gupshup webhook callbacks (inbound messages, verification probes, and delivery status receipts).
     * Supports both Gupshup format (v2) and Meta format (v3).
     */
    public function handle(Request $request, string $token): Response
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

        // Gupshup / Meta verification check (GET or webhook ping)
        if ($request->isMethod('get')) {
            if ($request->has('hub_challenge') || $request->has('hub.challenge')) {
                $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');
                return response($challenge, 200)->header('Content-Type', 'text/plain');
            }
            return response()->json(['status' => 'ok', 'verified' => true], 200);
        }

        return TenantContext::withoutTenancy(function () use ($request, $setting) {
            // Update last webhook activity timestamp so tenant knows webhook is alive
            $setting->update(['last_webhook_received_at' => now()]);

            $payload = $request->all();
            $rawContent = $request->getContent();

            // Handle empty payload when body is raw json string
            if (empty($payload) && !empty($rawContent)) {
                $decoded = json_decode($rawContent, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            // Handle form-urlencoded stringified inner payload
            if (isset($payload['payload']) && is_string($payload['payload'])) {
                $decodedInner = json_decode($payload['payload'], true);
                if (is_array($decodedInner)) {
                    $payload['payload'] = $decodedInner;
                }
            }

            Log::info("GupshupWebhook: Received payload", [
                'tenant_id' => $setting->tenant_id,
                'keys' => is_array($payload) ? array_keys($payload) : [],
            ]);

            // 1. Meta format (v3): standard WhatsApp Cloud API / Meta payload format
            if (isset($payload['entry']) || (isset($payload['object']) && $payload['object'] === 'whatsapp_business_account')) {
                $this->handleMetaFormatPayload($setting, $payload);
            }
            // 2. Gupshup format (v2): proprietary envelope with 'type' and 'payload'
            else {
                $eventType = $payload['type'] ?? null;
                $innerPayload = $payload['payload'] ?? [];

                if ($eventType === 'message') {
                    $this->handleInboundMessageV2($setting, $innerPayload, $payload);
                } elseif ($eventType === 'message-event') {
                    $this->handleMessageStatusEventV2($setting, $innerPayload);
                }
            }

            return response()->json(['status' => 'success'], 200);
        });
    }

    /**
     * Parse and process Meta format (v3) webhook payloads (WhatsApp Cloud API format).
     */
    protected function handleMetaFormatPayload(WhatsappSetting $setting, array $payload): void
    {
        $entries = $payload['entry'] ?? [];
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            if (!is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                if (!is_array($value)) {
                    continue;
                }

                // 1. Process Inbound Messages
                if (!empty($value['messages']) && is_array($value['messages'])) {
                    $contactsMap = [];
                    if (!empty($value['contacts']) && is_array($value['contacts'])) {
                        foreach ($value['contacts'] as $contact) {
                            $waId = $contact['wa_id'] ?? null;
                            $name = $contact['profile']['name'] ?? null;
                            if ($waId && $name) {
                                $contactsMap[$waId] = $name;
                            }
                        }
                    }

                    foreach ($value['messages'] as $msg) {
                        $this->processMetaInboundMessage($setting, $msg, $contactsMap, $payload);
                    }
                }

                // 2. Process Delivery Status Receipts (sent, delivered, read, failed)
                if (!empty($value['statuses']) && is_array($value['statuses'])) {
                    foreach ($value['statuses'] as $statusItem) {
                        $this->processMetaStatusEvent($setting, $statusItem);
                    }
                }
            }
        }
    }

    /**
     * Process an individual message from a Meta format (v3) webhook.
     */
    protected function processMetaInboundMessage(
        WhatsappSetting $setting,
        array $msg,
        array $contactsMap,
        array $fullPayload
    ): void {
        $rawPhone = $msg['from'] ?? null;
        if (!$rawPhone) {
            return;
        }

        $gupshupMsgId = $msg['id'] ?? null;
        if ($gupshupMsgId && WhatsappMessage::withoutGlobalScopes()->where('gupshup_message_id', $gupshupMsgId)->exists()) {
            return;
        }

        $senderName = $contactsMap[$rawPhone] ?? null;
        $msgType = $msg['type'] ?? 'text';
        $bodyText = '';
        $mediaUrl = null;

        switch ($msgType) {
            case 'text':
                $bodyText = $msg['text']['body'] ?? '';
                break;
            case 'interactive':
                $interactive = $msg['interactive'] ?? [];
                $iType = $interactive['type'] ?? '';
                if ($iType === 'button_reply') {
                    $bodyText = $interactive['button_reply']['title'] ?? '[رد تفاعلي]';
                } elseif ($iType === 'list_reply') {
                    $bodyText = $interactive['list_reply']['title'] ?? '[اختيار من القائمة]';
                } else {
                    $bodyText = '[رد تفاعلي]';
                }
                break;
            case 'button':
                $bodyText = $msg['button']['text'] ?? '[رد تفاعلي]';
                break;
            case 'image':
                $bodyText = !empty($msg['image']['caption']) ? $msg['image']['caption'] : '📷 [صورة مرفقة]';
                $mediaUrl = $msg['image']['id'] ?? null;
                break;
            case 'video':
                $bodyText = !empty($msg['video']['caption']) ? $msg['video']['caption'] : '🎥 [فيديو مرفق]';
                $mediaUrl = $msg['video']['id'] ?? null;
                break;
            case 'audio':
            case 'voice':
                $bodyText = '🎤 [رسالة صوتية]';
                $mediaUrl = $msg['audio']['id'] ?? ($msg['voice']['id'] ?? null);
                break;
            case 'document':
                $fileName = $msg['document']['filename'] ?? 'مستند';
                $bodyText = !empty($msg['document']['caption']) ? $msg['document']['caption'] : "📄 [ملف: {$fileName}]";
                $mediaUrl = $msg['document']['id'] ?? null;
                break;
            case 'location':
                $loc = $msg['location'] ?? [];
                $locName = $loc['name'] ?? ($loc['address'] ?? 'موقع جغرافي');
                $bodyText = "📍 [موقع جغرافي: {$locName}]";
                break;
            case 'contacts':
                $bodyText = '👤 [جهات اتصال مشتركة]';
                break;
            case 'reaction':
                $emoji = $msg['reaction']['emoji'] ?? '👍';
                $bodyText = "تفاعل: {$emoji}";
                break;
            default:
                $bodyText = '[رسالة واردة عبر واتساب]';
                break;
        }

        $this->saveInboundMessage(
            setting: $setting,
            rawSourcePhone: (string) $rawPhone,
            senderName: $senderName,
            gupshupMsgId: $gupshupMsgId,
            msgType: $msgType,
            bodyText: $bodyText,
            mediaUrl: $mediaUrl,
            fullPayload: $fullPayload
        );
    }

    /**
     * Process an individual delivery status event from a Meta format (v3) webhook.
     */
    protected function processMetaStatusEvent(WhatsappSetting $setting, array $statusItem): void
    {
        $waId = $statusItem['id'] ?? null;
        $eventStatus = strtolower((string) ($statusItem['status'] ?? ''));

        if (!$waId || !$eventStatus) {
            return;
        }

        $mappedStatus = match ($eventStatus) {
            'sent' => 'sent',
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
            ->where('gupshup_message_id', $waId)
            ->latest()
            ->first();

        if ($message) {
            $errorReason = null;
            if ($mappedStatus === 'failed') {
                $err = $statusItem['errors'][0] ?? [];
                $errorReason = $err['message'] ?? ($err['title'] ?? 'فشل التسليم من مزود الخدمة Meta / Gupshup');
            }

            $message->update([
                'status' => $mappedStatus,
                'error_message' => $errorReason ?: $message->error_message,
            ]);
        }
    }

    /**
     * Handle inbound messages in Gupshup format (v2).
     */
    protected function handleInboundMessageV2(WhatsappSetting $setting, array $innerPayload, array $fullPayload): void
    {
        $rawSourcePhone = $innerPayload['source']
            ?? ($innerPayload['sender']['phone'] ?? null);

        if (!$rawSourcePhone) {
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

        $this->saveInboundMessage(
            setting: $setting,
            rawSourcePhone: (string) $rawSourcePhone,
            senderName: $senderName,
            gupshupMsgId: $gupshupMsgId,
            msgType: $msgType,
            bodyText: $bodyText,
            mediaUrl: $mediaUrl,
            fullPayload: $fullPayload
        );
    }

    /**
     * Handle message delivery status updates in Gupshup format (v2).
     */
    protected function handleMessageStatusEventV2(WhatsappSetting $setting, array $innerPayload): void
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

    /**
     * Unified persistence for inbound messages (works with both v2 and v3 payloads).
     */
    protected function saveInboundMessage(
        WhatsappSetting $setting,
        string $rawSourcePhone,
        ?string $senderName,
        ?string $gupshupMsgId,
        string $msgType,
        string $bodyText,
        ?string $mediaUrl,
        array $fullPayload
    ): void {
        $normalizedPhone = PhoneNormalizer::toE164((string) $rawSourcePhone, $setting->default_country_code ?? '966');
        if (!$normalizedPhone) {
            Log::warning("GupshupWebhook: Could not normalize phone number {$rawSourcePhone}");
            return;
        }

        // Match with latest complaint sharing this normalized phone in this tenant
        $latestComplaint = Complaint::withoutGlobalScopes()
            ->where('tenant_id', $setting->tenant_id)
            ->where(function ($q) use ($normalizedPhone, $rawSourcePhone) {
                $q->where('normalized_phone', $normalizedPhone)
                  ->orWhere('phone', $rawSourcePhone);
            })
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

        Log::info("GupshupWebhook: Inbound message saved successfully", [
            'tenant_id' => $setting->tenant_id,
            'conversation_id' => $conversation->id,
            'phone' => $normalizedPhone,
            'msg_id' => $gupshupMsgId,
        ]);
    }
}
