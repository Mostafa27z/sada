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

class TenantIndustryNewsController extends Controller
{
    use ApiResponse;

    /**
     * Get the latest/today's top 10 industry news with actionable suggestions.
     */
    public function today(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        if (!$tenantId) {
            return $this->error(__('messages.forbidden'), 403);
        }

        $todayKsa = Carbon::now('Asia/Riyadh')->toDateString();

        // 1. Check if we have records for today
        $news = TenantIndustryNews::where('tenant_id', $tenantId)
            ->where('batch_date', $todayKsa)
            ->orderBy('rank', 'asc')
            ->get();

        // 2. If not yet generated for today, fetch the most recent batch date
        if ($news->isEmpty()) {
            $latestDate = TenantIndustryNews::where('tenant_id', $tenantId)
                ->max('batch_date');

            if ($latestDate) {
                $news = TenantIndustryNews::where('tenant_id', $tenantId)
                    ->where('batch_date', $latestDate)
                    ->orderBy('rank', 'asc')
                    ->get();
            }
        }

        return $this->success([
            'batch_date' => $news->first()?->batch_date?->toDateString() ?? $todayKsa,
            'is_today' => ($news->first()?->batch_date?->toDateString() === $todayKsa),
            'count' => $news->count(),
            'items' => $news,
        ], 'تم جلب رادار أخبار القطاع بنجاح');
    }

    /**
     * List historical industry news digests by date or status.
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

        $news = $query->orderBy('batch_date', 'desc')
            ->orderBy('rank', 'asc')
            ->paginate($request->query('per_page', 15));

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
     */
    public function trigger(Request $request, IndustryNewsService $service): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return $this->error(__('messages.forbidden'), 403);
        }

        $isSync = $request->boolean('sync', false);

        if ($isSync) {
            $items = $service->fetchAndProcessDailyNewsForTenant($tenant);
            return $this->success([
                'count' => count($items),
                'items' => $items,
            ], 'تم جلب وتحليل أهم أخبار القطاع وصياغة المقترحات بنجاح');
        }

        FetchTenantIndustryNewsJob::dispatch($tenant->id);

        return $this->success(null, 'تم إرسال مهمة رصد أخبار القطاع إلى المعالجة الخلفية');
    }
}
