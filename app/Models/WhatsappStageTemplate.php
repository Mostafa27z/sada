<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappStageTemplate extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'stage',
        'is_enabled',
        'dispatch_mode',
        'gupshup_template_id',
        'body_template',
        'variable_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'variable_order' => 'array',
    ];

    public const STAGE_LABELS_AR = [
        'new' => 'جديد',
        'under_review' => 'قيد المراجعة',
        'contacted' => 'تم التواصل مع المستفيد',
        'processing' => 'قيد المعالجة',
        'waiting_beneficiary' => 'بانتظار رد المستفيد',
        'resolved' => 'تم الحل',
        'closed' => 'مغلق',
        'escalated' => 'تم التصعيد',
        'reopened' => 'أعيد فتح الشكوى',
    ];

    public static function presetTemplateCatalog(): array
    {
        return [
            [
                'id' => 'tpl_stage_general_ar',
                'element_name' => 'complaint_stage_update_ar',
                'title_ar' => 'قالب تحديث مرحلة البلاغ الشامل (4 متغيرات)',
                'language_code' => 'ar',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'source' => 'preset',
                'body' => "مرحباً {{1}} 👋،\nنود إشعارك بأن حالة بلاغك رقم #{{2}} لدى {{3}} قد تم تحديثها إلى مرحلة: *{{4}}*.",
                'placeholders' => ['1', '2', '3', '4'],
                'default_variable_order' => ['complainer_name', 'tracking_number', 'tenant_name', 'stage_name_ar'],
            ],
            [
                'id' => 'tpl_stage_with_tracking_link_ar',
                'element_name' => 'complaint_stage_with_link_ar',
                'title_ar' => 'قالب إشعار المرحلة مع رابط المتابعة (4 متغيرات)',
                'language_code' => 'ar',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'source' => 'preset',
                'body' => "عزيزي {{1}}،\nتم تحديث مرحلة بلاغك رقم #{{2}} إلى: *{{3}}*.\nيمكنك متابعة كافة التفاصيل عبر الرابط المباشر:\n{{4}}",
                'placeholders' => ['1', '2', '3', '4'],
                'default_variable_order' => ['complainer_name', 'tracking_number', 'stage_name_ar', 'tracking_url'],
            ],
            [
                'id' => 'tpl_new_complaint_received_ar',
                'element_name' => 'complaint_received_ack_ar',
                'title_ar' => 'قالب تأكيد استلام بلاغ جديد (4 متغيرات)',
                'language_code' => 'ar',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'source' => 'preset',
                'body' => "مرحباً {{1}} 👋،\nتم استلام بلاغك رقم #{{2}} بنجاح وحالته الحالية: *{{3}}*.\nرابط متابعة البلاغ: {{4}}",
                'placeholders' => ['1', '2', '3', '4'],
                'default_variable_order' => ['complainer_name', 'tracking_number', 'stage_name_ar', 'tracking_url'],
            ],
            [
                'id' => 'tpl_waiting_beneficiary_reply_ar',
                'element_name' => 'complaint_waiting_customer_ar',
                'title_ar' => 'قالب طلب إفادة أو رد من المستفيد (3 متغيرات)',
                'language_code' => 'ar',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'source' => 'preset',
                'body' => "مرحباً {{1}}،\nبخصوص بلاغك رقم #{{2}}، تم تحديث الحالة إلى: *{{3}}*. نرجو منك تزويدنا بالتفاصيل المطلوبة عبر الرد على هذه الرسالة لاستكمال المعالجة.",
                'placeholders' => ['1', '2', '3'],
                'default_variable_order' => ['complainer_name', 'tracking_number', 'stage_name_ar'],
            ],
            [
                'id' => 'tpl_complaint_resolved_ar',
                'element_name' => 'complaint_resolved_closing_ar',
                'title_ar' => 'قالب إشعار حل البلاغ وإغلاقه (4 متغيرات)',
                'language_code' => 'ar',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'source' => 'preset',
                'body' => "مرحباً {{1}} ✨،\nيسعدنا إبلاغك بأنه *{{2}}* لبلاغك رقم #{{3}} لدى {{4}}.\nنسعد دائماً بخدمتك!",
                'placeholders' => ['1', '2', '3', '4'],
                'default_variable_order' => ['complainer_name', 'stage_name_ar', 'tracking_number', 'tenant_name'],
            ],
            [
                'id' => 'tpl_stage_transition_detailed_ar',
                'element_name' => 'complaint_stage_transition_ar',
                'title_ar' => 'قالب انتقال المرحلة من سابقة إلى جديدة (5 متغيرات)',
                'language_code' => 'ar',
                'category' => 'UTILITY',
                'status' => 'APPROVED',
                'source' => 'preset',
                'body' => "عزيزي {{1}}،\nنود إفادتك بانتقال بلاغك رقم #{{2}} في {{3}} من مرحلة ({{4}}) إلى مرحلة: *{{5}}*.",
                'placeholders' => ['1', '2', '3', '4', '5'],
                'default_variable_order' => ['complainer_name', 'tracking_number', 'location_name', 'previous_stage_name_ar', 'stage_name_ar'],
            ],
        ];
    }

    public static function defaultTemplates(): array
    {
        return [
            'new' => [
                'is_enabled' => true,
                'gupshup_template_id' => 'tpl_new_complaint_received_ar',
                'body_template' => "مرحباً {{1}} 👋،\nتم استلام بلاغك رقم #{{2}} بنجاح وحالته الحالية: *{{3}}*.\nرابط متابعة البلاغ: {{4}}",
                'variable_order' => ['complainer_name', 'tracking_number', 'stage_name_ar', 'tracking_url'],
            ],
            'under_review' => [
                'is_enabled' => true,
                'gupshup_template_id' => 'tpl_stage_with_tracking_link_ar',
                'body_template' => "عزيزي {{1}}،\nتم تحديث مرحلة بلاغك رقم #{{2}} إلى: *{{3}}*.\nيمكنك متابعة كافة التفاصيل عبر الرابط المباشر:\n{{4}}",
                'variable_order' => ['complainer_name', 'tracking_number', 'stage_name_ar', 'tracking_url'],
            ],
            'contacted' => [
                'is_enabled' => true,
                'gupshup_template_id' => 'tpl_stage_general_ar',
                'body_template' => "مرحباً {{1}} 👋،\nنود إشعارك بأن حالة بلاغك رقم #{{2}} لدى {{3}} قد تم تحديثها إلى مرحلة: *{{4}}*.",
                'variable_order' => ['complainer_name', 'tracking_number', 'tenant_name', 'stage_name_ar'],
            ],
            'processing' => [
                'is_enabled' => true,
                'gupshup_template_id' => 'tpl_stage_with_tracking_link_ar',
                'body_template' => "عزيزي {{1}}،\nتم تحديث مرحلة بلاغك رقم #{{2}} إلى: *{{3}}*.\nيمكنك متابعة كافة التفاصيل عبر الرابط المباشر:\n{{4}}",
                'variable_order' => ['complainer_name', 'tracking_number', 'stage_name_ar', 'tracking_url'],
            ],
            'waiting_beneficiary' => [
                'is_enabled' => true,
                'gupshup_template_id' => 'tpl_waiting_beneficiary_reply_ar',
                'body_template' => "مرحباً {{1}}،\nبخصوص بلاغك رقم #{{2}}، تم تحديث الحالة إلى: *{{3}}*. نرجو منك تزويدنا بالتفاصيل المطلوبة عبر الرد على هذه الرسالة لاستكمال المعالجة.",
                'variable_order' => ['complainer_name', 'tracking_number', 'stage_name_ar'],
            ],
            'resolved' => [
                'is_enabled' => true,
                'gupshup_template_id' => 'tpl_complaint_resolved_ar',
                'body_template' => "مرحباً {{1}} ✨،\nيسعدنا إبلاغك بأنه *{{2}}* لبلاغك رقم #{{3}} لدى {{4}}.\nنسعد دائماً بخدمتك!",
                'variable_order' => ['complainer_name', 'stage_name_ar', 'tracking_number', 'tenant_name'],
            ],
            'closed' => [
                'is_enabled' => false,
                'gupshup_template_id' => 'tpl_stage_general_ar',
                'body_template' => "مرحباً {{1}} 👋،\nنود إشعارك بأن حالة بلاغك رقم #{{2}} لدى {{3}} قد تم تحديثها إلى مرحلة: *{{4}}*.",
                'variable_order' => ['complainer_name', 'tracking_number', 'tenant_name', 'stage_name_ar'],
            ],
            'escalated' => [
                'is_enabled' => true,
                'gupshup_template_id' => 'tpl_stage_transition_detailed_ar',
                'body_template' => "عزيزي {{1}}،\nنود إفادتك بانتقال بلاغك رقم #{{2}} في {{3}} من مرحلة ({{4}}) إلى مرحلة: *{{5}}*.",
                'variable_order' => ['complainer_name', 'tracking_number', 'location_name', 'previous_stage_name_ar', 'stage_name_ar'],
            ],
            'reopened' => [
                'is_enabled' => true,
                'gupshup_template_id' => 'tpl_stage_with_tracking_link_ar',
                'body_template' => "عزيزي {{1}}،\nتم تحديث مرحلة بلاغك رقم #{{2}} إلى: *{{3}}*.\nيمكنك متابعة كافة التفاصيل عبر الرابط المباشر:\n{{4}}",
                'variable_order' => ['complainer_name', 'tracking_number', 'stage_name_ar', 'tracking_url'],
            ],
        ];
    }

    public static function ensureDefaultsForTenant(int $tenantId): \Illuminate\Support\Collection
    {
        $defaults = self::defaultTemplates();
        foreach ($defaults as $stage => $config) {
            self::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'stage' => $stage,
                ],
                [
                    'is_enabled' => $config['is_enabled'],
                    'dispatch_mode' => 'gupshup_template',
                    'gupshup_template_id' => $config['gupshup_template_id'],
                    'body_template' => $config['body_template'],
                    'variable_order' => $config['variable_order'],
                ]
            );
        }

        return self::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('stage');
    }
}

