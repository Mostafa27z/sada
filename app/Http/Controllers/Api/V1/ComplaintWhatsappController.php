<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Models\WhatsappStageTemplate;
use App\Services\GupshupWhatsappService;
use App\Support\ApiResponse;
use App\Support\PhoneNormalizer;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplaintWhatsappController extends Controller
{
    use ApiResponse;

    /**
     * Get Gupshup WhatsApp integration settings & webhook URL for current tenant.
     */
    public function getSettings(): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $setting = WhatsappSetting::forTenant($tenantId);

        return $this->success($this->formatSetting($setting));
    }

    /**
     * Update Gupshup WhatsApp integration settings for current tenant.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $setting = WhatsappSetting::forTenant($tenantId);

        $validated = $request->validate([
            'app_id' => ['nullable', 'string', 'max:190'],
            'app_name' => ['nullable', 'string', 'max:190'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'source_phone' => ['nullable', 'string', 'max:32'],
            'default_country_code' => ['required', 'string', 'max:8'],
            'is_active' => ['required', 'boolean'],
            'auto_send_on_stage_change' => ['required', 'boolean'],
            'auto_send_on_new_complaint' => ['required', 'boolean'],
        ]);

        // If api_key is masked placeholder, keep existing key
        if (isset($validated['api_key']) && str_contains($validated['api_key'], '••••')) {
            unset($validated['api_key']);
        }

        if (!empty($validated['source_phone'])) {
            $validated['source_phone'] = PhoneNormalizer::toE164(
                $validated['source_phone'],
                $validated['default_country_code']
            );
        }

        $setting->update($validated);

        return $this->success(
            $this->formatSetting($setting->fresh()),
            'تم حفظ إعدادات الربط مع Gupshup WhatsApp بنجاح'
        );
    }

    /**
     * Regenerate unique Gupshup webhook token for current tenant.
     */
    public function regenerateWebhookToken(): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $setting = WhatsappSetting::forTenant($tenantId);

        $setting->update([
            'webhook_token' => WhatsappSetting::generateUniqueToken(),
        ]);

        return $this->success(
            $this->formatSetting($setting->fresh()),
            'تم توليد رابط Webhook جديد بنجاح'
        );
    }

    /**
     * Get all per-stage automatic WhatsApp message templates and real Gupshup templates for current tenant.
     */
    public function getTemplates(GupshupWhatsappService $whatsappService): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $setting = WhatsappSetting::forTenant($tenantId);
        $templates = WhatsappStageTemplate::ensureDefaultsForTenant($tenantId);
        $gupshupResult = $whatsappService->fetchGupshupTemplates($setting);

        $formatted = [];
        foreach (WhatsappStageTemplate::STAGE_LABELS_AR as $stageKey => $stageLabelAr) {
            /** @var WhatsappStageTemplate|null $tpl */
            $tpl = $templates->get($stageKey);
            $formatted[] = [
                'stage' => $stageKey,
                'stage_label_ar' => $stageLabelAr,
                'is_enabled' => $tpl ? (bool) $tpl->is_enabled : false,
                'dispatch_mode' => $tpl->dispatch_mode ?? 'gupshup_template',
                'gupshup_template_id' => $tpl->gupshup_template_id,
                'body_template' => $tpl->body_template ?? '',
                'variable_order' => $tpl->variable_order ?? ['complainer_name', 'tracking_number', 'stage_name_ar', 'tracking_url'],
            ];
        }

        return $this->success([
            'templates' => $formatted,
            'available_templates' => $gupshupResult['templates'],
            'gupshup_sync' => [
                'success' => $gupshupResult['success'],
                'is_configured' => $gupshupResult['is_configured'],
                'error' => $gupshupResult['error'],
            ],
            'available_variables' => [
                ['key' => 'complainer_name', 'label' => 'اسم صاحب الشكوى', 'example' => 'أحمد محمد العتيبي'],
                ['key' => 'stage_name_ar', 'label' => 'اسم المرحلة الجديدة (بالعربية)', 'example' => 'قيد المعالجة'],
                ['key' => 'previous_stage_name_ar', 'label' => 'اسم المرحلة السابقة (بالعربية)', 'example' => 'جديد'],
                ['key' => 'tracking_number', 'label' => 'رقم البلاغ / التذكرة', 'example' => '20261101_01_4829'],
                ['key' => 'tracking_url', 'label' => 'رابط متابعة الشكوى', 'example' => 'https://miraah.sa/complaint/track?tracking_number=20261101_01_4829'],
                ['key' => 'location_name', 'label' => 'اسم الفرع', 'example' => 'الفرع الرئيسي - الرياض'],
                ['key' => 'tenant_name', 'label' => 'اسم المنشأة', 'example' => 'منشأة مرآة النموذجية'],
            ],
        ]);
    }

    /**
     * Create and submit a new WhatsApp template directly to Gupshup / Meta API.
     */
    public function createGupshupTemplate(
        Request $request,
        GupshupWhatsappService $whatsappService
    ): JsonResponse {
        $tenantId = TenantContext::getTenantId();
        $setting = WhatsappSetting::forTenant($tenantId);

        $validated = $request->validate([
            'element_name' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9_]+$/'],
            'language_code' => ['required', 'string', 'max:16'],
            'category' => ['required', 'string', 'in:UTILITY,MARKETING,AUTHENTICATION'],
            'template_type' => ['nullable', 'string', 'in:TEXT,IMAGE,VIDEO,DOCUMENT'],
            'vertical' => ['nullable', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:1024'],
            'example' => ['required', 'string', 'max:1024'],
        ], [
            'element_name.regex' => 'اسم القالب البرمجي (elementName) يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطة سفلية (_) فقط بدون مسافات.',
        ]);

        $result = $whatsappService->createGupshupTemplate($setting, $validated);

        if (!$result['success']) {
            return $this->error($result['message'], 422);
        }

        $gupshupList = $whatsappService->fetchGupshupTemplates($setting);

        return $this->success([
            'created_template' => $result['template'],
            'available_templates' => $gupshupList['templates'],
        ], $result['message']);
    }

    /**
     * Delete a Gupshup template by ID / elementName.
     */
    public function deleteGupshupTemplate(
        Request $request,
        string $templateId,
        GupshupWhatsappService $whatsappService
    ): JsonResponse {
        $tenantId = TenantContext::getTenantId();
        $setting = WhatsappSetting::forTenant($tenantId);

        $elementName = $request->query('element_name');
        $result = $whatsappService->deleteGupshupTemplate($setting, $templateId, $elementName);
        $gupshupList = $whatsappService->fetchGupshupTemplates($setting->fresh());

        return $this->success([
            'available_templates' => $gupshupList['templates'],
        ], $result['message']);
    }

    /**
     * Save all per-stage automatic WhatsApp message templates for current tenant.
     */
    public function updateTemplates(Request $request, GupshupWhatsappService $whatsappService): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'templates' => ['required', 'array'],
            'templates.*.stage' => ['required', 'string'],
            'templates.*.is_enabled' => ['required', 'boolean'],
            'templates.*.dispatch_mode' => ['nullable', 'string', 'in:session_text,gupshup_template'],
            'templates.*.gupshup_template_id' => ['nullable', 'string', 'max:120'],
            'templates.*.body_template' => ['required', 'string', 'max:2000'],
            'templates.*.variable_order' => ['nullable', 'array'],
        ]);

        foreach ($validated['templates'] as $item) {
            if (!array_key_exists($item['stage'], WhatsappStageTemplate::STAGE_LABELS_AR)) {
                continue;
            }

            WhatsappStageTemplate::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'stage' => $item['stage'],
                ],
                [
                    'is_enabled' => (bool) $item['is_enabled'],
                    'dispatch_mode' => $item['dispatch_mode'] ?? 'gupshup_template',
                    'gupshup_template_id' => $item['gupshup_template_id'] ?? null,
                    'body_template' => $item['body_template'],
                    'variable_order' => $item['variable_order'] ?? ['complainer_name', 'tracking_number', 'stage_name_ar', 'tracking_url'],
                ]
            );
        }

        return $this->getTemplates($whatsappService);
    }

    /**
     * Get the unified WhatsApp conversation for a specific phone number (or complaint ID),
     * including all complaints that share this phone number and the full message history.
     */
    public function conversationByPhone(
        Request $request,
        GupshupWhatsappService $whatsappService
    ): JsonResponse {
        $tenantId = TenantContext::getTenantId();
        $setting = WhatsappSetting::forTenant($tenantId);

        $rawPhone = $request->query('phone');
        $complaintId = $request->query('complaint_id');
        $contactName = null;

        if ($complaintId) {
            $complaint = Complaint::where('tenant_id', $tenantId)->find((int) $complaintId);
            if ($complaint) {
                $rawPhone = $rawPhone ?: $complaint->phone;
                $contactName = $complaint->name;
            }
        }

        if (empty($rawPhone)) {
            return $this->error('رقم الهاتف غير متوفر لهذه الشكوى.', 422);
        }

        $normalizedPhone = PhoneNormalizer::toE164((string) $rawPhone, $setting->default_country_code ?? '966');
        if (!$normalizedPhone) {
            return $this->error('تعذر قراءة صيغة رقم الهاتف.', 422);
        }

        $conversation = $whatsappService->getOrCreateConversation($tenantId, $normalizedPhone, $contactName);

        // Mark conversation as read when opened by support agent
        if ($conversation && $conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
        }

        // Fetch all complaints sharing this normalized phone in this tenant
        $relatedComplaints = Complaint::where('tenant_id', $tenantId)
            ->where(function ($q) use ($normalizedPhone, $rawPhone) {
                $q->where('normalized_phone', $normalizedPhone)
                  ->orWhere('phone', $rawPhone);
            })
            ->with('location:id,business_name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Complaint $c) => [
                'id' => $c->id,
                'tracking_number' => $c->tracking_number ?: (string) $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'stage' => $c->stage ?? $c->status ?? 'new',
                'stage_label_ar' => WhatsappStageTemplate::STAGE_LABELS_AR[$c->stage ?? 'new'] ?? ($c->stage ?? 'جديد'),
                'priority' => $c->priority ?? 'neutral',
                'opinion' => $c->opinion,
                'location_name' => $c->location?->business_name,
                'created_at' => $c->created_at?->toISOString(),
            ]);

        $messages = $conversation
            ? $conversation->messages()->with(['senderUser:id,name,avatar', 'complaint:id,tracking_number,stage'])->get()->map(fn (WhatsappMessage $m) => $this->formatMessage($m))
            : [];

        return $this->success([
            'conversation' => [
                'id' => $conversation?->id,
                'normalized_phone' => $normalizedPhone,
                'raw_phone' => $rawPhone,
                'contact_name' => $conversation?->contact_name ?: ($relatedComplaints->first()['name'] ?? 'عميل واتساب'),
                'is_session_window_open' => $conversation ? $conversation->isSessionWindowOpen() : false,
                'last_inbound_at' => $conversation?->last_inbound_at?->toISOString(),
                'last_message_at' => $conversation?->last_message_at?->toISOString(),
                'unread_count' => 0,
            ],
            'is_gupshup_configured' => !empty($setting->api_key) && !empty($setting->source_phone) && !empty($setting->app_name),
            'is_whatsapp_active' => (bool) $setting->is_active,
            'related_complaints' => $relatedComplaints,
            'messages' => $messages,
        ]);
    }

    /**
     * Send a message in a unified phone conversation via Gupshup.
     */
    public function sendMessage(
        Request $request,
        int $conversationId,
        GupshupWhatsappService $whatsappService
    ): JsonResponse {
        $tenantId = TenantContext::getTenantId();

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
            'complaint_id' => ['nullable', 'integer'],
        ]);

        $conversation = WhatsappConversation::where('tenant_id', $tenantId)->find($conversationId);
        if (!$conversation) {
            return $this->error('المحادثة غير موجودة.', 404);
        }

        $message = $whatsappService->sendChatMessage(
            $conversation,
            $validated['body'],
            auth()->id(),
            $validated['complaint_id'] ?? null
        );

        return $this->success(
            $this->formatMessage($message),
            $message->status === 'failed'
                ? 'تم تسجيل الرسالة في المحادثة ولكن تعذر إرسالها عبر Gupshup: ' . $message->error_message
                : 'تم إرسال رسالة واتساب بنجاح'
        );
    }

    protected function formatSetting(WhatsappSetting $setting): array
    {
        return [
            'id' => $setting->id,
            'tenant_id' => $setting->tenant_id,
            'app_id' => $setting->app_id ?? '',
            'app_name' => $setting->app_name ?? '',
            'api_key_masked' => !empty($setting->api_key)
                ? substr($setting->api_key, 0, 4) . '••••••••••••••••' . substr($setting->api_key, -4)
                : '',
            'has_api_key' => !empty($setting->api_key),
            'source_phone' => $setting->source_phone ?? '',
            'default_country_code' => $setting->default_country_code ?? '966',
            'webhook_token' => $setting->webhook_token,
            'webhook_url' => $setting->webhook_url,
            'is_active' => (bool) $setting->is_active,
            'auto_send_on_stage_change' => (bool) $setting->auto_send_on_stage_change,
            'auto_send_on_new_complaint' => (bool) $setting->auto_send_on_new_complaint,
            'last_webhook_received_at' => $setting->last_webhook_received_at?->toISOString(),
        ];
    }

    protected function formatMessage(WhatsappMessage $m): array
    {
        return [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'complaint_id' => $m->complaint_id,
            'complaint_tracking_number' => $m->complaint?->tracking_number ?: ($m->complaint_id ? (string) $m->complaint_id : null),
            'triggered_by_stage' => $m->triggered_by_stage,
            'triggered_by_stage_label_ar' => $m->triggered_by_stage
                ? (WhatsappStageTemplate::STAGE_LABELS_AR[$m->triggered_by_stage] ?? $m->triggered_by_stage)
                : null,
            'direction' => $m->direction,
            'message_type' => $m->message_type,
            'body' => $m->body,
            'media_url' => $m->media_url,
            'status' => $m->status,
            'error_message' => $m->error_message,
            'gupshup_message_id' => $m->gupshup_message_id,
            'sender_user' => $m->senderUser ? [
                'id' => $m->senderUser->id,
                'name' => $m->senderUser->name,
                'avatar' => $m->senderUser->avatar,
            ] : null,
            'created_at' => $m->created_at?->toISOString(),
        ];
    }
}

