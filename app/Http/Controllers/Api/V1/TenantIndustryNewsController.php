<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\FetchTenantIndustryNewsJob;
use App\Models\Tenant;
use App\Models\TenantIndustryNews;
use App\Services\IndustryNewsService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TenantIndustryNewsController extends Controller
{
    use ApiResponse;

    /**
     * Get the latest/today's top industry news with actionable suggestions and pagination.
     */
    public function today(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        if (!$tenantId) {
            return $this->error(__('messages.forbidden'), 403);
        }

        $todayKsa = Carbon::now('Asia/Riyadh')->toDateString();

        // 1. Determine target batch date
        $targetDate = $todayKsa;
        $hasToday = TenantIndustryNews::where('tenant_id', $tenantId)
            ->where('batch_date', $todayKsa)
            ->exists();

        if (!$hasToday) {
            $latestDate = TenantIndustryNews::where('tenant_id', $tenantId)
                ->max('batch_date');
            if ($latestDate) {
                $targetDate = Carbon::parse($latestDate)->toDateString();
            }
        }

        $baseQuery = TenantIndustryNews::where('tenant_id', $tenantId)
            ->where('batch_date', $targetDate);

        // Compute batch stats for header
        $allBatchItems = (clone $baseQuery)->get();
        $total = $allBatchItems->count();
        $acted = $allBatchItems->where('status', 'acted')->count();
        $dismissed = $allBatchItems->where('status', 'dismissed')->count();
        $unread = $allBatchItems->where('status', 'unread')->count();
        $highUrgencyCount = $allBatchItems->filter(function ($item) {
            return data_get($item->suggested_actions, 'operational_action.urgency') === 'high';
        })->count();

        // Today's Top 10 items
        $items = (clone $baseQuery)->orderBy('rank', 'asc')->limit(10)->get();

        // Compute daily manual refresh quota (max 3 times daily per tenant)
        $maxTriggers = 3;
        $triggersCacheKey = "industry_news_triggers_{$tenantId}_{$todayKsa}";
        $usedTriggers = (int) Cache::get($triggersCacheKey, 0);
        $triggersLeft = max(0, $maxTriggers - $usedTriggers);

        return $this->success([
            'batch_date' => $targetDate,
            'is_today' => ($targetDate === $todayKsa),
            'count' => $items->count(),
            'total' => $total,
            'items' => $items,
            'triggers_left' => $triggersLeft,
            'triggers_used' => $usedTriggers,
            'max_triggers' => $maxTriggers,
            'stats' => [
                'total' => $total,
                'acted' => $acted,
                'unread' => $unread,
                'dismissed' => $dismissed,
                'highUrgencyCount' => $highUrgencyCount,
            ],
        ], 'تم جلب رادار أخبار القطاع بنجاح');
    }

    /**
     * List historical industry news digests by date, status, search, or category with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        if (!$tenantId) {
            return $this->error(__('messages.forbidden'), 403);
        }

        $query = TenantIndustryNews::where('tenant_id', $tenantId);

        if ($request->filled('date')) {
            $query->where('batch_date', $request->query('date'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim($request->query('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhere('summary', 'like', $search)
                  ->orWhere('why_it_matters', 'like', $search)
                  ->orWhere('source_name', 'like', $search);
            });
        }

        $perPage = (int) $request->query('per_page', 9);
        $page = (int) $request->query('page', 1);

        $news = $query->orderBy('batch_date', 'desc')
            ->orderBy('rank', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->success($news);
    }

    /**
     * View details of a specific news item and its recommendations.
     */
    public function show(int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $item = TenantIndustryNews::where('tenant_id', $tenantId)->find($id);

        if (!$item) {
            return $this->error('الخبر غير موجود', 404);
        }

        return $this->success($item);
    }

    /**
     * Update status of an action/news item (acted, dismissed, unread).
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $item = TenantIndustryNews::where('tenant_id', $tenantId)->find($id);

        if (!$item) {
            return $this->error('الخبر غير موجود', 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:unread,acted,dismissed',
        ]);

        $item->update(['status' => $validated['status']]);

        return $this->success($item, 'تم تحديث حالة الخبر بنجاح');
    }

    /**
     * Trigger immediate real-time fetch of today's industry news for this tenant.
     * Enforces strict limit of 3 manual triggers per day per tenant.
     */
    public function trigger(Request $request, IndustryNewsService $service): JsonResponse
    {
        $tenantId = TenantContext::getTenantId() ?? $request->user()?->current_tenant_id ?? $request->user()?->tenant_id;
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        if (!$tenant) {
            return $this->error(__('messages.forbidden'), 403);
        }

        $todayKsa = Carbon::now('Asia/Riyadh')->toDateString();
        $triggersCacheKey = "industry_news_triggers_{$tenant->id}_{$todayKsa}";
        $maxTriggers = 3;
        $usedCount = (int) Cache::get($triggersCacheKey, 0);

        if ($usedCount >= $maxTriggers) {
            return $this->error(
                'لقد استنفدت الحد اليومي المسموح به لتحديث رادار الأخبار يدوياً (3 مرات كحد أقصى يومياً). يتم التحديث الآلي للنشرة تلقائياً كل يوم الساعة 8:00 مساءً.',
                429,
                [
                    'code' => 'trigger_limit_exceeded',
                    'triggers_used' => $usedCount,
                    'max_triggers' => $maxTriggers,
                    'triggers_left' => 0,
                ]
            );
        }

        $newCount = $usedCount + 1;
        $secondsUntilMidnight = (int) max(60, Carbon::now('Asia/Riyadh')->diffInSeconds(Carbon::now('Asia/Riyadh')->endOfDay()) + 60);
        Cache::put($triggersCacheKey, $newCount, $secondsUntilMidnight);
        $triggersLeft = max(0, $maxTriggers - $newCount);

        $isSync = $request->boolean('sync', false);

        if ($isSync) {
            $items = $service->fetchAndProcessDailyNewsForTenant($tenant);
            return $this->success([
                'count' => count($items),
                'items' => $items,
                'triggers_left' => $triggersLeft,
                'triggers_used' => $newCount,
                'max_triggers' => $maxTriggers,
            ], 'تم جلب وتحليل أهم أخبار القطاع وصياغة المقترحات بنجاح');
        }

        FetchTenantIndustryNewsJob::dispatch($tenant->id);

        return $this->success([
            'triggers_left' => $triggersLeft,
            'triggers_used' => $newCount,
            'max_triggers' => $maxTriggers,
        ], 'تم إرسال مهمة رصد أخبار القطاع إلى المعالجة الخلفية');
    }
}
