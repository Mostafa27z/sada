<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Trend\AnalyzeTrendRequest;
use App\Http\Resources\TrendResource;
use App\Jobs\AnalyzeTrendsJob;
use App\Models\Trend;
use App\Services\TrendService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TrendController extends Controller
{
    use ApiResponse;

    /**
     * List past topic trend analyses for the active tenant.
     */
    public function index()
    {
        $query = Trend::query();

        if (request()->has('status')) {
            $query->where('status', request('status'));
        }

        if (request()->has('search')) {
            $search = request('search');
            $query->where('topic', 'like', "%{$search}%");
        }

        $trends = $query->latest()->paginate(request('per_page', 15));

        return $this->paginated($trends, TrendResource::class);
    }

    /**
     * Trigger topic trend discovery and analysis via queue or synchronously.
     */
    public function analyze(AnalyzeTrendRequest $request, TrendService $trendService)
    {
        $tenantId = TenantContext::getTenantId();
        if (!$tenantId) {
            return $this->error(__('messages.forbidden'), 403);
        }

        $validated = $request->validated();
        $topic = trim($validated['topic']);
        $limit = intval($validated['limit'] ?? 50);
        $rawPlatforms = $validated['platforms'] ?? ['facebook', 'instagram', 'tiktok', 'twitter'];
        $country = $validated['country'] ?? 'SA';
        $dateFrom = $validated['date_from'] ?? null;
        $dateTo = $validated['date_to'] ?? null;

        // Normalize platform names ('x' => 'twitter')
        $platforms = array_values(array_unique(array_map(function ($p) {
            return strtolower($p) === 'x' ? 'twitter' : strtolower($p);
        }, $rawPlatforms)));

        $trend = Trend::create([
            'tenant_id' => $tenantId,
            'created_by' => $request->user()?->id,
            'topic' => $topic,
            'status' => Trend::STATUS_PENDING,
            'limit' => $limit,
            'platforms' => $platforms,
            'country' => $country,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        if ($request->boolean('sync')) {
            try {
                $trend->update(['status' => Trend::STATUS_PROCESSING]);
                $result = $trendService->runPipeline(
                    topic: $topic,
                    limit: $limit,
                    platforms: $platforms,
                    country: $country,
                    brandName: null,
                    dateFrom: $dateFrom,
                    dateTo: $dateTo
                );

                $trend->update([
                    'status' => Trend::STATUS_COMPLETED,
                    'keywords' => $result['keywords'] ?? [],
                    'hashtags' => $result['hashtags'] ?? [],
                    'raw_data_count' => $result['raw_data_count'] ?? 0,
                    'trends_count' => $result['trends_count'] ?? 0,
                    'trends_analysis' => $result['trends_analysis'] ?? [],
                ]);

                return $this->success(new TrendResource($trend->fresh()), 'Trend analysis completed successfully.');
            } catch (\Throwable $e) {
                $trend->update([
                    'status' => Trend::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);

                return $this->error("Trend analysis failed: " . $e->getMessage(), 500);
            }
        }

        // Asynchronous Queue Execution (Default)
        AnalyzeTrendsJob::dispatch(
            trendId: $trend->id,
            tenantId: $tenantId,
            topic: $topic,
            limit: $limit,
            platforms: $platforms,
            country: $country,
            dateFrom: $dateFrom,
            dateTo: $dateTo
        );

        return $this->success(
            new TrendResource($trend),
            'Trend discovery task queued successfully.',
            202
        );
    }

    /**
     * Get specific trend analysis details and status.
     */
    public function show(int $id)
    {
        $trend = Trend::find($id);

        if (!$trend) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new TrendResource($trend));
    }

    /**
     * Delete a trend analysis record.
     */
    public function destroy(int $id)
    {
        $trend = Trend::find($id);

        if (!$trend) {
            return $this->error(__('messages.not_found'), 404);
        }

        $trend->delete();

        return $this->success(null, __('messages.deleted'));
    }

    /**
     * Synthesize all monitored trend posts into a unified Master Trend Post.
     * Enforces strict limit of 3 generations per topic/tenant per day.
     */
     public function generateMasterPost(Request $request, \App\Services\GeminiAnalyticsService $geminiService)
     {
         $request->validate([
             'topic' => ['required', 'string'],
             'posts' => ['required', 'array'],
             'tone' => ['sometimes', 'nullable', 'string'],
         ]);
 
         $tenantId = TenantContext::getTenantId() ?? $request->user()?->current_tenant_id ?? $request->user()?->tenant_id ?? 'global';
         $topic = trim($request->input('topic'));
         $topicKey = md5(mb_strtolower($topic));
         $todayKsa = Carbon::now('Asia/Riyadh')->toDateString();
         $cacheKey = "master_post_gen_{$tenantId}_{$topicKey}_{$todayKsa}";
         $maxGenerations = 3;
 
         $usedCount = (int) Cache::get($cacheKey, 0);
 
         if ($usedCount >= $maxGenerations) {
             return $this->error(
                 'لقد استنفدت الحد المسموح به لتوليد المحتوى بالذكاء الاصطناعي لهذا الموضوع (3 مرات كحد أقصى يومياً). يرجى المحاولة غداً أو اختيار موضوع آخر.',
                 429,
                 [
                     'code' => 'generation_limit_exceeded',
                     'generations_used' => $usedCount,
                     'max_generations' => $maxGenerations,
                     'generations_left' => 0,
                 ]
             );
         }
 
         $result = $geminiService->generateMasterTrendPost(
             $topic,
             $request->input('posts', []),
             $request->input('tone')
         );
 
         $newCount = $usedCount + 1;
         $secondsUntilMidnight = (int) max(60, Carbon::now('Asia/Riyadh')->diffInSeconds(Carbon::now('Asia/Riyadh')->endOfDay()) + 60);
         Cache::put($cacheKey, $newCount, $secondsUntilMidnight);
         $generationsLeft = max(0, $maxGenerations - $newCount);
 
         $result['generations_left'] = $generationsLeft;
         $result['generations_used'] = $newCount;
         $result['max_generations'] = $maxGenerations;
 
         return $this->success($result, 'Master trend post generated successfully.');
     }
 
     /**
      * Get remaining quota for master post generation on a topic today.
      */
     public function masterPostStatus(Request $request)
     {
         $tenantId = TenantContext::getTenantId() ?? $request->user()?->current_tenant_id ?? $request->user()?->tenant_id ?? 'global';
         $topic = trim((string) $request->query('topic', ''));
         $topicKey = md5(mb_strtolower($topic));
         $todayKsa = Carbon::now('Asia/Riyadh')->toDateString();
         $cacheKey = "master_post_gen_{$tenantId}_{$topicKey}_{$todayKsa}";
         $maxGenerations = 3;
 
         $usedCount = (int) Cache::get($cacheKey, 0);
 
         return $this->success([
             'generations_used' => $usedCount,
             'generations_left' => max(0, $maxGenerations - $usedCount),
             'max_generations' => $maxGenerations,
         ]);
     }
}
