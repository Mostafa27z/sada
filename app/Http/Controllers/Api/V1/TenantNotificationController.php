<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Notifications\MorningDigestNotification;
use App\Notifications\ReputationAlertNotification;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class TenantNotificationController extends Controller
{
    use ApiResponse;

    /**
     * Get current tenant notification settings and team members list.
     */
    public function getSettings(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $users = TenantContext::withoutTenancy(function () use ($tenant) {
            return $tenant->users()->get();
        });

        $settings = $tenant->settings['notifications'] ?? [
            'reputation_alert' => [
                'enabled' => true,
                'threshold' => 70,
                'target_type' => 'admins', // 'admins' or 'custom'
                'recipients' => [],
                'custom_emails' => [],
            ],
            'morning_digest' => [
                'enabled' => true,
                'time' => '08:00',
                'target_type' => 'admins', // 'admins' or 'custom'
                'recipients' => [],
                'custom_emails' => [],
            ],
        ];

        return $this->success([
            'settings' => $settings,
            'team_members' => UserResource::collection($users),
        ]);
    }

    /**
     * Update notification settings for the current active tenant.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error(__('messages.tenant_not_resolved'), 400);
        }

        $validated = $request->validate([
            'reputation_alert' => ['required', 'array'],
            'reputation_alert.enabled' => ['required', 'boolean'],
            'reputation_alert.threshold' => ['required', 'numeric', 'min:20', 'max:100'],
            'reputation_alert.target_type' => ['required', 'string', 'in:admins,custom,all'],
            'reputation_alert.recipients' => ['nullable', 'array'],
            'reputation_alert.recipients.*' => ['nullable'],
            'reputation_alert.custom_emails' => ['nullable', 'array'],
            'reputation_alert.custom_emails.*' => ['nullable'],

            'morning_digest' => ['required', 'array'],
            'morning_digest.enabled' => ['required', 'boolean'],
            'morning_digest.time' => ['nullable', 'string'],
            'morning_digest.target_type' => ['required', 'string', 'in:admins,custom,all'],
            'morning_digest.recipients' => ['nullable', 'array'],
            'morning_digest.recipients.*' => ['nullable'],
            'morning_digest.custom_emails' => ['nullable', 'array'],
            'morning_digest.custom_emails.*' => ['nullable'],
        ]);

        $currentSettings = is_array($tenant->settings) ? $tenant->settings : [];
        $currentSettings['notifications'] = $validated;

        $tenant->update(['settings' => $currentSettings]);

        return $this->success([
            'settings' => $validated,
        ], 'تم حفظ إعدادات وتفضيلات التنبيهات بنجاح');
    }

    /**
     * Trigger a live test email for either Morning Digest or Reputation Alert.
     */
    public function testSend(Request $request): JsonResponse
    {
        $tenant = TenantContext::getTenant();
        $user = $request->user();

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:reputation,morning_digest'],
            'email' => ['nullable', 'email'],
        ]);

        $targetEmail = $validated['email'] ?? $user?->email;

        if (!$targetEmail) {
            return $this->error('يرجى تحديد عنوان بريد إلكتروني لإرسال التجربة إليه.', 422);
        }

        $tenantName = $tenant?->name ?? 'منصة مرآة للرصد';

        if ($validated['type'] === 'reputation') {
            $threshold = $tenant?->settings['notifications']['reputation_alert']['threshold'] ?? 70;
            $notificationData = [
                'tenant_name' => $tenantName,
                'threshold' => $threshold,
                'current_score' => 58,
                'negative_count' => 14,
            ];
            $notification = new ReputationAlertNotification($notificationData);

            Notification::route('mail', $targetEmail)->notify($notification);

            return $this->success([
                'type' => 'reputation',
                'target_email' => $targetEmail,
                'message' => "تم إرسال تنبيه السمعة التجريبي بنجاح إلى: {$targetEmail}",
                'preview' => [
                    'subject' => "⚠️ [عاجل] تنبيه تراجع مؤشر السمعة العام لـ {$tenantName} دون {$threshold}%",
                    'greeting' => "مرحباً {$user?->name}،",
                    'lines' => [
                        "رصدت خوارزميات الذكاء الاصطناعي والاستشعار الإعلامي تراجعاً حرجاً في مؤشر السمعة الرقمي لـ {$tenantName}:",
                        "• مؤشر السمعة الحالي: 58% (الحد الحرج المعتمد: {$threshold}%)",
                        "• إجمالي الإشارات والمشاركات السلبية المرصودة: 14 تفاعل سلبي",
                        "• التوصية الفورية: يوصى بتدخل فريق العلاقات العامة والاتصال المؤسسي للتفاعل السريع مع الاستفسارات والحد من اتساع الأثر السلبي.",
                    ],
                    'action_text' => 'الانتقال إلى لوحة السمعة الرقمية',
                    'action_url' => config('app.frontend_url', 'http://localhost:3000') . '/dashboard/reputation',
                ],
            ]);
        } else {
            $notificationData = [
                'tenant_name' => $tenantName,
                'date' => now()->format('Y-m-d'),
                'articles_count' => 42,
                'interactions_count' => 1250,
                'positive_pct' => 74,
                'neutral_pct' => 18,
                'negative_pct' => 8,
            ];
            $notification = new MorningDigestNotification($notificationData);

            Notification::route('mail', $targetEmail)->notify($notification);

            return $this->success([
                'type' => 'morning_digest',
                'target_email' => $targetEmail,
                'message' => "تم إرسال التقرير الصباحي التجريبي بنجاح إلى: {$targetEmail}",
                'preview' => [
                    'subject' => "☀️ التقرير والملخص الصباحي اليومي لـ {$tenantName} | " . now()->format('Y-m-d'),
                    'greeting' => "صباح الخير {$user?->name}،",
                    'lines' => [
                        "إليك الإيجاز الصباحي الموحّد لنتائج الرصد وتحليلات الرأي العام خلال آخر 24 ساعة لـ {$tenantName}:",
                        "📊 المؤشرات الرئيسية لليوم:",
                        "• إجمالي المواد والمشاركات المرصودة: 42 منشوراً ومقالاً",
                        "• حجم التفاعل الجماهيري الإجمالي: 1,250 تفاعل",
                        "• توزيع مؤشر المشاعر: 74% إيجابي | 18% محايد | 8% سلبي",
                        "💡 الملخص التنفيذي وتوصيات الذكاء الاصطناعي:",
                        "استقرار إيجابي عام في الانطباعات مع نشاط ملحوظ على منصات التواصل. يُنصح بمواصلة نشر المحتوى التفاعلي والتجاوب السريع مع استفسارات الجمهور لتعزيز الحضور الرقمي.",
                    ],
                    'action_text' => 'فتح لوحة المراقبة والتحليلات',
                    'action_url' => config('app.frontend_url', 'http://localhost:3000') . '/dashboard',
                ],
            ]);
        }
    }
}
