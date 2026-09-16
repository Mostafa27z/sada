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
        ]);

        if ($request->boolean('sync')) {
            try {
                $trend->update(['status' => Trend::STATUS_PROCESSING]);
                $result = $trendService->runPipeline($topic, $limit, $platforms, $country);

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
            country: $country
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
}
