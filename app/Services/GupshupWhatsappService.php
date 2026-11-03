<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\Tenant;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Models\WhatsappStageTemplate;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GupshupWhatsappService
{
    public const SESSION_MSG_ENDPOINT = 'https://api.gupshup.io/wa/api/v1/msg';
    public const TEMPLATE_MSG_ENDPOINT = 'https://api.gupshup.io/wa/api/v1/template/msg';

    /**
     * Find or create the unified WhatsApp conversation for a tenant and phone number.
     */
    public function getOrCreateConversation(
        int $tenantId,
        string $rawOrNormalizedPhone,
        ?string $contactName = null
    ): ?WhatsappConversation {
        $setting = WhatsappSetting::forTenant($tenantId);
        $normalizedPhone = PhoneNormalizer::toE164($rawOrNormalizedPhone, $setting->default_country_code ?? '966');

        if (!$normalizedPhone) {
            return null;
        }

        $conversation = WhatsappConversation::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'normalized_phone' => $normalizedPhone,
            ],
            [
                'raw_phone' => $rawOrNormalizedPhone,
                'contact_name' => $contactName ?: 'عميل واتساب',
                'unread_count' => 0,
            ]
        );

        if ($contactName && (empty($conversation->contact_name) || $conversation->contact_name === 'عميل واتساب')) {
            $conversation->update(['contact_name' => $contactName]);
        }

        return $conversation;
    }

    /**
     * Build variable dictionary and render template string for a given complaint and stage.
     */
    public function buildTemplateVariables(Complaint $complaint, string $newStage, ?string $oldStage = null): array
    {
        $complaint->loadMissing(['location']);
        $tenant = Tenant::withoutGlobalScopes()->find($complaint->tenant_id);

        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $trackingCode = $complaint->tracking_number ?: (string) $complaint->id;
        $trackingUrl = "{$frontendUrl}/complaint/track?tracking_number=" . urlencode($trackingCode);

        $stageLabels = WhatsappStageTemplate::STAGE_LABELS_AR;
        $newStageLabel = $stageLabels[$newStage] ?? $newStage;
        $oldStageLabel = $oldStage ? ($stageLabels[$oldStage] ?? $oldStage) : 'جديد';

        return [
            'complainer_name' => $complaint->name ?: 'عميلنا العزيز',
            'stage_name_ar' => $newStageLabel,
            'previous_stage_name_ar' => $oldStageLabel,
            'tracking_number' => $trackingCode,
            'tracking_url' => $trackingUrl,
            'location_name' => $complaint->location?->business_name ?: ($tenant?->name ?: 'الفرع الرئيسي'),
            'tenant_name' => $tenant?->name ?: 'منصة مرآة',
        ];
    }

    /**
     * Replace both positional ({{1}}, {{2}}, ...) and named ({{complainer_name}}, ...) placeholders.
     */
    public function renderTemplateText(string $templateBody, array $variables, array $variableOrder = []): string
    {
        $rendered = $templateBody;

        // 1. Replace positional placeholders {{1}}, {{2}}, {{3}} based on mapped $variableOrder
        foreach (array_values($variableOrder) as $idx => $varKey) {
            $pos = $idx + 1;
            $val = (string) ($variables[$varKey] ?? '');
            $rendered = str_replace(
                ["{{{$pos}}}", "{{ {$pos} }}"],
                $val,
                $rendered
            );
        }

        // 2. Replace named placeholders {{complainer_name}}, {{stage_name_ar}}, etc.
        foreach ($variables as $key => $value) {
            $rendered = str_replace(
                ["{{{$key}}}", "{{ {$key} }}"],
                (string) $value,
                $rendered
            );
        }

        return $rendered;
    }

    /**
     * Extract positional placeholders (e.g. ['1', '2', '3']) from a Gupshup template body.
     */
    public function extractPlaceholdersFromBody(string $body): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);
        if (empty($matches[1])) {
            return [];
        }
        $unique = array_values(array_unique($matches[1]));
        sort($unique, SORT_NUMERIC);
        return array_map('strval', $unique);
    }

    /**
     * Fetch ONLY real templates from Gupshup WhatsApp API (`GET https://api.gupshup.io/wa/app/{app_id}/template`).
     */
    public function fetchGupshupTemplates(WhatsappSetting $setting): array
    {
        $appTarget = trim((string) ($setting->app_id ?: $setting->app_name));

        if (empty($setting->api_key) || empty($appTarget)) {
            return [
                'success' => false,
                'is_configured' => false,
                'templates' => [],
                'error' => 'يرجى إدخال مفتاح Gupshup API Key ومعرّف التطبيق (App ID / App Name) في تبويب إعدادات الربط لجلب القوالب المعتمدة من Meta.',
            ];
        }

        $defaultVarCycle = [
            'complainer_name',
            'tracking_number',
            'stage_name_ar',
            'tracking_url',
            'tenant_name',
            'location_name',
            'previous_stage_name_ar',
        ];

        $endpoints = [];
        if (!empty($setting->app_id)) {
            $endpoints[] = "https://api.gupshup.io/wa/app/" . urlencode(trim($setting->app_id)) . "/template";
        }
        if (!empty($setting->app_name)) {
            $endpoints[] = "https://api.gupshup.io/wa/app/" . urlencode(trim($setting->app_name)) . "/template";
            $endpoints[] = "https://api.gupshup.io/sm/api/v1/template/list/" . urlencode(trim($setting->app_name));
        }
        $endpoints = array_values(array_unique($endpoints));

        $lastError = null;

        foreach ($endpoints as $url) {
            try {
                $response = Http::withHeaders([
                    'apikey' => $setting->api_key,
                    'accept' => 'application/json',
                ])->timeout(15)->get($url);

                $json = $response->json() ?? [];

                if ($response->successful() && (isset($json['templates']) || ($json['status'] ?? '') === 'success')) {
                    $list = $json['templates'] ?? $json['data'] ?? [];
                    $remoteTemplates = [];
                    $hiddenList = is_array($setting->hidden_gupshup_templates) ? $setting->hidden_gupshup_templates : [];

                    if (is_array($list)) {
                        foreach ($list as $item) {
                            $tplId = (string) ($item['id'] ?? ($item['elementName'] ?? ''));
                            $elementName = (string) ($item['elementName'] ?? $item['name'] ?? 'template');

                            if (in_array($tplId, $hiddenList, true) || in_array($elementName, $hiddenList, true)) {
                                continue;
                            }

                            $tplBody = (string) ($item['data'] ?? $item['content'] ?? $item['body'] ?? '');
                            if (empty($tplBody)) {
                                continue;
                            }
                            $placeholders = $this->extractPlaceholdersFromBody($tplBody);
                            $defaultOrder = [];
                            foreach ($placeholders as $idx => $_) {
                                $defaultOrder[] = $defaultVarCycle[$idx % count($defaultVarCycle)];
                            }

                            $lang = (string) ($item['languageCode'] ?? $item['language'] ?? 'ar');
                            $status = strtoupper((string) ($item['status'] ?? 'PENDING'));
                            $category = strtoupper((string) ($item['category'] ?? 'UTILITY'));
                            $templateType = strtoupper((string) ($item['templateType'] ?? 'TEXT'));

                            // Parse meta example if stored as JSON in containerMeta
                            $exampleText = $item['example'] ?? null;
                            if (!$exampleText && !empty($item['containerMeta'])) {
                                $metaDecoded = is_string($item['containerMeta'])
                                    ? json_decode($item['containerMeta'], true)
                                    : $item['containerMeta'];
                                $exampleText = $metaDecoded['sampleText'] ?? $metaDecoded['data'] ?? null;
                            }

                            $remoteTemplates[] = [
                                'id' => $tplId ?: $elementName,
                                'element_name' => $elementName,
                                'title_ar' => "{$elementName} ({$lang})",
                                'language_code' => $lang,
                                'category' => $category,
                                'template_type' => $templateType,
                                'status' => $status,
                                'reason' => $item['reason'] ?? null,
                                'source' => 'gupshup',
                                'body' => $tplBody,
                                'example' => $exampleText,
                                'placeholders' => $placeholders,
                                'default_variable_order' => $defaultOrder,
                                'created_on' => $item['createdOn'] ?? null,
                            ];
                        }
                    }

                    return [
                        'success' => true,
                        'is_configured' => true,
                        'templates' => $remoteTemplates,
                        'error' => null,
                    ];
                }

                $lastError = $json['message'] ?? $response->body() ?: ('HTTP ' . $response->status());
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        return [
            'success' => false,
            'is_configured' => true,
            'templates' => [],
            'error' => 'تعذر جلب القوالب من Gupshup API: ' . (is_string($lastError) ? $lastError : json_encode($lastError, JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * Validate Meta WhatsApp Template restrictions before sending to Gupshup:
     * 1. Variables ({{1}}, {{2}}) cannot be at the very start or very end of the template
     *    (even if preceded/followed only by punctuation or symbols like '.', '!', '*').
     * 2. Variables cannot be directly adjacent without words between them.
     */
    public function validateMetaTemplateContent(string $content): ?string
    {
        $trimmed = trim($content);

        // Check if template starts with {{N}} (ignoring leading punctuation/whitespace)
        if (preg_match('/^[^\p{L}\p{N}]*\{\{\s*\d+\s*\}\}/u', $trimmed)) {
            return 'قوانين Meta تمنع وضع متغير مثل {{1}} في بداية نص القالب. يجب أن يبدأ القالب بكلمات ثابتة مثل: "مرحباً {{1}}، ...".';
        }

        // Check if template ends with {{N}} (ignoring trailing punctuation/symbols like '.', '!', '*', ')')
        if (preg_match('/\{\{\s*\d+\s*\}\}[^\p{L}\p{N}]*$/u', $trimmed)) {
            return 'قوانين Meta تمنع وضع متغير في نهاية نص القالب (حتى لو تبعته نقطة "." أو علامة ترقيم). يرجى إضافة عبارة ختامية بعد آخر متغير مثل: "... وجاري متابعة طلبك باهتمام." أو "... شكراً لتواصلك معنا.".';
        }

        // Check adjacent variables without words between them
        if (preg_match('/\{\{\s*\d+\s*\}\}[^\p{L}\p{N}]*\{\{\s*\d+\s*\}\}/u', $trimmed)) {
            return 'قوانين Meta تمنع وضع متغيرين متتاليين مباشرة مثل {{1}} {{2}} بدون كلمات تفصل بينهما.';
        }

        return null;
    }

    /**
     * Create and submit a new WhatsApp template to Meta via Gupshup API:
     * POST https://api.gupshup.io/wa/app/{app_id}/template
     */
    public function createGupshupTemplate(WhatsappSetting $setting, array $data): array
    {
        $validationError = $this->validateMetaTemplateContent((string) ($data['content'] ?? ''));
        if ($validationError) {
            return [
                'success' => false,
                'message' => $validationError,
                'template' => null,
            ];
        }

        $appTarget = trim((string) ($setting->app_id ?: $setting->app_name));

        if (empty($setting->api_key) || empty($appTarget)) {
            return [
                'success' => false,
                'message' => 'يرجى حفظ مفتاح Gupshup API Key ومعرّف التطبيق (App ID) أولاً في تبويب إعدادات الربط.',
                'template' => null,
            ];
        }

        $url = "https://api.gupshup.io/wa/app/" . urlencode($appTarget) . "/template";

        $formPayload = [
            'elementName' => $data['element_name'],
            'languageCode' => $data['language_code'] ?? 'ar',
            'category' => strtoupper($data['category'] ?? 'UTILITY'),
            'templateType' => strtoupper($data['template_type'] ?? 'TEXT'),
            'vertical' => $data['vertical'] ?? 'Ticket update',
            'content' => $data['content'],
            'example' => $data['example'],
            'enableSample' => 'true',
            'allowTemplateCategoryChange' => 'true',
        ];

        try {
            $response = Http::asForm()
                ->withHeaders([
                    'apikey' => $setting->api_key,
                    'accept' => 'application/json',
                ])
                ->timeout(20)
                ->post($url, $formPayload);

            $json = $response->json() ?? [];

            if ($response->successful() && (($json['status'] ?? '') === 'success' || isset($json['template']) || isset($json['id']))) {
                // If this element_name was previously hidden/deleted, unhide it
                try {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('whatsapp_settings', 'hidden_gupshup_templates')) {
                        $hidden = is_array($setting->hidden_gupshup_templates) ? $setting->hidden_gupshup_templates : [];
                        $hidden = array_values(array_filter($hidden, fn ($h) => $h !== $data['element_name']));
                        $setting->update(['hidden_gupshup_templates' => $hidden]);
                    }
                } catch (\Throwable) {
                }

                $created = $json['template'] ?? $json;
                return [
                    'success' => true,
                    'message' => 'تم إرسال القالب إلى Gupshup / Meta للمراجعة والاعتماد بنجاح!',
                    'template' => $created,
                ];
            }

            $errMsg = $json['message'] ?? $response->body() ?: ('HTTP ' . $response->status());
            return [
                'success' => false,
                'message' => 'رفض Gupshup إنشاء القالب: ' . (is_string($errMsg) ? $errMsg : json_encode($errMsg, JSON_UNESCAPED_UNICODE)),
                'template' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'خطأ في الاتصال بـ Gupshup API: ' . $e->getMessage(),
                'template' => null,
            ];
        }
    }

    /**
     * Delete a WhatsApp template via Gupshup API and remove it from the tenant's active template catalog.
     */
    public function deleteGupshupTemplate(
        WhatsappSetting $setting,
        string $templateId,
        ?string $elementName = null
    ): array {
        $appTarget = trim((string) ($setting->app_id ?: $setting->app_name));
        $targetElement = trim((string) ($elementName ?: $templateId));
        $deletedRemotely = false;

        if (!empty($setting->api_key) && !empty($appTarget)) {
            $deleteUrls = [
                "https://api.gupshup.io/wa/app/" . urlencode($appTarget) . "/template/" . urlencode($targetElement),
                "https://api.gupshup.io/wa/app/" . urlencode($appTarget) . "/template/" . urlencode($targetElement) . "/" . urlencode($templateId),
                "https://partner.gupshup.io/partner/app/" . urlencode($appTarget) . "/template/" . urlencode($targetElement),
            ];

            foreach (array_unique($deleteUrls) as $url) {
                try {
                    $res = Http::withHeaders([
                        'apikey' => $setting->api_key,
                        'Authorization' => $setting->api_key,
                        'accept' => 'application/json',
                    ])->timeout(10)->delete($url);

                    if ($res->successful()) {
                        $deletedRemotely = true;
                        break;
                    }
                } catch (\Throwable) {
                    // Continue fallback
                }
            }
        }

        // Also record in hidden_gupshup_templates so even if Gupshup sandbox blocks remote DELETE on FAILED templates,
        // it is immediately removed from the tenant's template catalog.
        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('whatsapp_settings', 'hidden_gupshup_templates')) {
                $hidden = is_array($setting->hidden_gupshup_templates) ? $setting->hidden_gupshup_templates : [];
                $hidden[] = $templateId;
                if ($elementName) {
                    $hidden[] = $elementName;
                }
                $setting->update([
                    'hidden_gupshup_templates' => array_values(array_unique(array_filter($hidden))),
                ]);
            }
        } catch (\Throwable) {
        }

        // Unlink from any stage templates using this deleted template ID
        WhatsappStageTemplate::withoutGlobalScopes()
            ->where('tenant_id', $setting->tenant_id)
            ->whereIn('gupshup_template_id', array_filter([$templateId, $elementName]))
            ->update([
                'gupshup_template_id' => null,
                'is_enabled' => false,
            ]);

        return [
            'success' => true,
            'deleted_remotely' => $deletedRemotely,
            'message' => 'تم حذف القالب بنجاح وإزالته من قائمة القوالب.',
        ];
    }

    /**
     * Send a free-form session message from the chat widget to a conversation.
     */
    public function sendChatMessage(
        WhatsappConversation $conversation,
        string $bodyText,
        ?int $senderUserId = null,
        ?int $complaintId = null
    ): WhatsappMessage {
        $setting = WhatsappSetting::forTenant($conversation->tenant_id);

        $message = WhatsappMessage::withoutGlobalScopes()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'complaint_id' => $complaintId,
            'sender_user_id' => $senderUserId,
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => $bodyText,
            'status' => 'queued',
        ]);

        $conversation->update([
            'last_message_preview' => Str::limit($bodyText, 120),
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);

        $dispatchResult = $this->dispatchSessionMessageViaGupshup(
            $setting,
            $conversation->normalized_phone,
            $bodyText
        );

        $message->update([
            'gupshup_message_id' => $dispatchResult['message_id'] ?? null,
            'status' => $dispatchResult['status'],
            'error_message' => $dispatchResult['error'] ?? null,
            'metadata' => $dispatchResult['raw'] ?? null,
        ]);

        return $message->fresh(['senderUser', 'complaint']);
    }

    /**
     * Trigger automatic stage update WhatsApp notification for a complaint.
     */
    public function sendStageUpdateNotification(
        Complaint $complaint,
        string $newStage,
        ?string $oldStage = null,
        ?int $triggeredByUserId = null
    ): ?WhatsappMessage {
        if (empty($complaint->phone)) {
            return null;
        }

        $setting = WhatsappSetting::forTenant($complaint->tenant_id);

        if (!$setting->is_active) {
            return null;
        }

        if ($newStage === 'new' && !$setting->auto_send_on_new_complaint) {
            return null;
        }

        if ($newStage !== 'new' && !$setting->auto_send_on_stage_change) {
            return null;
        }

        $templates = WhatsappStageTemplate::ensureDefaultsForTenant($complaint->tenant_id);
        /** @var WhatsappStageTemplate|null $stageTemplate */
        $stageTemplate = $templates->get($newStage);

        if (!$stageTemplate || !$stageTemplate->is_enabled || empty(trim($stageTemplate->body_template))) {
            return null;
        }

        $conversation = $this->getOrCreateConversation(
            $complaint->tenant_id,
            $complaint->phone,
            $complaint->name
        );

        if (!$conversation) {
            return null;
        }

        $orderedVarKeys = is_array($stageTemplate->variable_order) && count($stageTemplate->variable_order) > 0
            ? $stageTemplate->variable_order
            : ['complainer_name', 'tracking_number', 'stage_name_ar', 'tracking_url'];

        $variables = $this->buildTemplateVariables($complaint, $newStage, $oldStage);
        $renderedBody = $this->renderTemplateText($stageTemplate->body_template, $variables, $orderedVarKeys);

        $message = WhatsappMessage::withoutGlobalScopes()->create([
            'tenant_id' => $complaint->tenant_id,
            'conversation_id' => $conversation->id,
            'complaint_id' => $complaint->id,
            'sender_user_id' => $triggeredByUserId,
            'direction' => 'outbound',
            'message_type' => 'template',
            'triggered_by_stage' => $newStage,
            'body' => $renderedBody,
            'status' => 'queued',
        ]);

        $conversation->update([
            'last_message_preview' => Str::limit($renderedBody, 120),
            'last_message_at' => now(),
        ]);

        $isPresetId = str_starts_with((string) $stageTemplate->gupshup_template_id, 'tpl_');

        if (
            $stageTemplate->dispatch_mode === 'gupshup_template' &&
            !empty($stageTemplate->gupshup_template_id) &&
            !$isPresetId
        ) {
            $params = array_map(fn ($key) => (string) ($variables[$key] ?? ''), $orderedVarKeys);

            $dispatchResult = $this->dispatchTemplateMessageViaGupshup(
                $setting,
                $conversation->normalized_phone,
                $stageTemplate->gupshup_template_id,
                $params,
                $renderedBody
            );
        } else {
            $dispatchResult = $this->dispatchSessionMessageViaGupshup(
                $setting,
                $conversation->normalized_phone,
                $renderedBody
            );
        }

        $message->update([
            'gupshup_message_id' => $dispatchResult['message_id'] ?? null,
            'status' => $dispatchResult['status'],
            'error_message' => $dispatchResult['error'] ?? null,
            'metadata' => $dispatchResult['raw'] ?? null,
        ]);

        $stageLabelAr = WhatsappStageTemplate::STAGE_LABELS_AR[$newStage] ?? $newStage;
        $complaint->activities()->create([
            'tenant_id' => $complaint->tenant_id,
            'user_id' => $triggeredByUserId,
            'type' => 'note',
            'comment' => "📲 تم إرسال إشعار واتساب تلقائي للمستفيد بخصوص مرحلة \"{$stageLabelAr}\":\n{$renderedBody}",
            'is_visible_to_customer' => false,
            'metadata' => [
                'channel' => 'whatsapp_gupshup',
                'whatsapp_message_id' => $message->id,
                'gupshup_message_id' => $dispatchResult['message_id'] ?? null,
                'status' => $dispatchResult['status'],
                'stage' => $newStage,
            ],
        ]);

        return $message;
    }

    /**
     * Execute HTTP POST to https://api.gupshup.io/wa/api/v1/msg
     */
    public function dispatchSessionMessageViaGupshup(
        WhatsappSetting $setting,
        string $destinationPhone,
        string $text
    ): array {
        $sourcePhone = PhoneNormalizer::toE164($setting->source_phone, $setting->default_country_code ?? '966');

        if (empty($setting->api_key) || empty($sourcePhone) || empty($setting->app_name)) {
            return [
                'status' => 'failed',
                'message_id' => null,
                'error' => 'بيانات الربط مع Gupshup غير مكتملة (API Key / App Name / Source Phone).',
                'raw' => null,
            ];
        }

        try {
            $response = Http::asForm()
                ->withHeaders([
                    'apikey' => $setting->api_key,
                    'Cache-Control' => 'no-cache',
                ])
                ->timeout(15)
                ->post(self::SESSION_MSG_ENDPOINT, [
                    'channel' => 'whatsapp',
                    'source' => $sourcePhone,
                    'destination' => $destinationPhone,
                    'src.name' => $setting->app_name,
                    'message' => json_encode([
                        'type' => 'text',
                        'text' => $text,
                    ], JSON_UNESCAPED_UNICODE),
                ]);

            $json = $response->json() ?? [];
            $gsMessageId = $json['messageId'] ?? $json['id'] ?? null;

            if ($response->successful() && (($json['status'] ?? '') === 'submitted' || $gsMessageId)) {
                return [
                    'status' => 'sent',
                    'message_id' => $gsMessageId,
                    'error' => null,
                    'raw' => $json,
                ];
            }

            $errMsg = $json['message'] ?? $response->body() ?: 'Gupshup API returned HTTP ' . $response->status();
            return [
                'status' => 'failed',
                'message_id' => $gsMessageId,
                'error' => is_string($errMsg) ? $errMsg : json_encode($errMsg, JSON_UNESCAPED_UNICODE),
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('Gupshup session message error: ' . $e->getMessage());
            return [
                'status' => 'failed',
                'message_id' => null,
                'error' => $e->getMessage(),
                'raw' => null,
            ];
        }
    }

    /**
     * Execute HTTP POST to https://api.gupshup.io/wa/api/v1/template/msg
     */
    public function dispatchTemplateMessageViaGupshup(
        WhatsappSetting $setting,
        string $destinationPhone,
        string $gupshupTemplateId,
        array $params,
        string $fallbackText = ''
    ): array {
        $sourcePhone = PhoneNormalizer::toE164($setting->source_phone, $setting->default_country_code ?? '966');

        if (empty($setting->api_key) || empty($sourcePhone) || empty($setting->app_name)) {
            return [
                'status' => 'failed',
                'message_id' => null,
                'error' => 'بيانات الربط مع Gupshup غير مكتملة (API Key / App Name / Source Phone).',
                'raw' => null,
            ];
        }

        try {
            $response = Http::asForm()
                ->withHeaders([
                    'apikey' => $setting->api_key,
                    'Cache-Control' => 'no-cache',
                ])
                ->timeout(15)
                ->post(self::TEMPLATE_MSG_ENDPOINT, [
                    'channel' => 'whatsapp',
                    'source' => $sourcePhone,
                    'destination' => $destinationPhone,
                    'src.name' => $setting->app_name,
                    'template' => json_encode([
                        'id' => $gupshupTemplateId,
                        'params' => array_values($params),
                    ], JSON_UNESCAPED_UNICODE),
                ]);

            $json = $response->json() ?? [];
            $gsMessageId = $json['messageId'] ?? $json['id'] ?? null;

            if ($response->successful() && (($json['status'] ?? '') === 'submitted' || $gsMessageId)) {
                return [
                    'status' => 'sent',
                    'message_id' => $gsMessageId,
                    'error' => null,
                    'raw' => $json,
                ];
            }

            $errMsg = $json['message'] ?? $response->body() ?: 'Gupshup Template API returned HTTP ' . $response->status();
            return [
                'status' => 'failed',
                'message_id' => $gsMessageId,
                'error' => is_string($errMsg) ? $errMsg : json_encode($errMsg, JSON_UNESCAPED_UNICODE),
                'raw' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('Gupshup template message error: ' . $e->getMessage());
            return [
                'status' => 'failed',
                'message_id' => null,
                'error' => $e->getMessage(),
                'raw' => null,
            ];
        }
    }
}

