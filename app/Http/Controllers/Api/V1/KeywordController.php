<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreKeywordRequest;
use App\Http\Requests\Monitoring\UpdateKeywordRequest;
use App\Http\Resources\KeywordResource;
use App\Models\Keyword;
use App\Support\ApiResponse;

class KeywordController extends Controller
{
    use ApiResponse;

    /**
     * List tenant keywords with search, filter, and pagination.
     */
    public function index()
    {
        $query = Keyword::query();

        if (request()->has('status')) {
            $query->where('status', request('status'));
        }

        if (request()->has('priority')) {
            $query->where('priority', request('priority'));
        }

        if (request()->has('search')) {
            $search = request('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $keywords = $query->latest()->paginate(request('per_page', 20));

        return $this->paginated($keywords, KeywordResource::class);
    }

    /**
     * Store new keyword in current active tenant.
     */
    public function store(StoreKeywordRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $keyword = Keyword::create($data);

        return $this->created(new KeywordResource($keyword));
    }

    /**
     * Get specific keyword details.
     */
    public function show(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new KeywordResource($keyword));
    }

    /**
     * Update keyword.
     */
    public function update(UpdateKeywordRequest $request, int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->update($request->validated());

        return $this->success(new KeywordResource($keyword->fresh()), __('messages.updated'));
    }

    /**
     * Soft delete keyword.
     */
    public function destroy(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->delete();

        return $this->success(null, __('messages.deleted'));
    }

    public function activate(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->update(['status' => Keyword::STATUS_ACTIVE]);
        return $this->success(new KeywordResource($keyword));
    }

    public function pause(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->update(['status' => Keyword::STATUS_PAUSED]);
        return $this->success(new KeywordResource($keyword));
    }

    public function archive(int $id)
    {
        $keyword = Keyword::find($id);

        if (!$keyword) {
            return $this->error(__('messages.not_found'), 404);
        }

        $keyword->update(['status' => Keyword::STATUS_ARCHIVED]);
        return $this->success(new KeywordResource($keyword));
    }

    /**
     * Trigger asynchronous AI keyword scraping across platforms.
     */
    public function triggerScrape(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'keywords' => ['nullable', 'array'],
            'keywords.*' => ['string'],
            'platforms' => ['sometimes', 'array'],
            'platform_limits' => ['sometimes', 'nullable', 'array'],
            'platform_limits.*' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'platform_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'country' => ['sometimes', 'nullable', 'string'],
            'date_from' => ['sometimes', 'nullable', 'string', 'max:20'],
            'date_to' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $tenantId = \App\Support\TenantContext::getTenantId();
        if (!$tenantId) {
            return $this->error(__('messages.forbidden'), 403);
        }

        $tenant = \App\Support\TenantContext::getTenant() ?? \App\Models\Tenant::find($tenantId);
        $isTrial = $tenant && $tenant->isTrial();
        $maxPerCampaign = $isTrial ? 25 : ($tenant?->plan?->max_articles_per_campaign ?? 1000);

        if ($tenant) {
            $usage = app(\App\Services\UsageLimitService::class)->getUsage($tenant);
            $remainingMonthly = $usage['articles']['remaining'] ?? $maxPerCampaign;
            if ($remainingMonthly < $maxPerCampaign) {
                $maxPerCampaign = max(0, $remainingMonthly);
            }
        }

        $keywords = $request->input('keywords', []);
        if (empty($keywords)) {
            $keywordRecords = Keyword::where('status', Keyword::STATUS_ACTIVE)->get();
            foreach ($keywordRecords as $kw) {
                $keywords[] = $kw->name;
                $config = $kw->configuration;
                if (!empty($config['keywords']) && is_array($config['keywords'])) {
                    foreach ($config['keywords'] as $subKw) {
                        if (!empty($subKw) && !in_array($subKw, $keywords)) {
                            $keywords[] = $subKw;
                        }
                    }
                }
            }
            $keywords = array_values(array_unique($keywords));
        }

        if (empty($keywords)) {
            return $this->error('No active keywords found for this tenant.', 422);
        }

        $platforms = $request->get('platforms', ['instagram', 'facebook', 'x', 'tiktok']);
        $rawPlatformLimits = $request->get('platform_limits', null);
        $platformLimits = [];

        if (!empty($rawPlatformLimits) && is_array($rawPlatformLimits)) {
            $totalReq = 0;
            foreach ($rawPlatformLimits as $p => $lim) {
                $intLim = max(0, (int)$lim);
                $platformLimits[strtolower(trim((string)$p))] = $intLim;
                $totalReq += $intLim;
            }
            if ($totalReq > $maxPerCampaign) {
                if ($maxPerCampaign <= 0) {
                    $platformLimits = array_fill_keys(array_keys($platformLimits), 0);
                } else {
                    $ratio = $maxPerCampaign / $totalReq;
                    $clampedTotal = 0;
                    $keys = array_keys($platformLimits);
                    $lastIdx = count($keys) - 1;
                    foreach ($keys as $idx => $p) {
                        if ($idx === $lastIdx) {
                            $platformLimits[$p] = max(0, $maxPerCampaign - $clampedTotal);
                        } else {
                            $scaled = (int) floor($platformLimits[$p] * $ratio);
                            $platformLimits[$p] = $scaled;
                            $clampedTotal += $scaled;
                        }
                    }
                }
            }
            $limit = array_sum($platformLimits);
        } elseif ($request->filled('platform_limit') && (int)$request->get('platform_limit') > 0) {
            $singlePlatLim = (int)$request->get('platform_limit');
            $totalReq = $singlePlatLim * count($platforms);
            if ($totalReq > $maxPerCampaign) {
                $singlePlatLim = max(1, intval(floor($maxPerCampaign / max(1, count($platforms)))));
            }
            foreach ($platforms as $p) {
                $platformLimits[$p] = $singlePlatLim;
            }
            $limit = array_sum($platformLimits);
        } else {
            $rawLimit = (int) $request->get('limit', 50);
            $limit = min($rawLimit > 0 ? $rawLimit : 50, $maxPerCampaign);
            $perPlat = max(1, intval(ceil($limit / max(1, count($platforms)))));
            foreach ($platforms as $p) {
                $platformLimits[$p] = $perPlat;
            }
        }

        \App\Jobs\ScrapeKeywordsJob::dispatch(
            $tenantId,
            $keywords,
            $platforms,
            $request->get('country'),
            null,
            $request->get('date_from'),
            $request->get('date_to'),
            $limit,
            $platformLimits
        );

        return $this->success(null, 'Keyword scraping task queued successfully.', 202);
    }
}
