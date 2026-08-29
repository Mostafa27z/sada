<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    use ApiResponse;

    public function sentiment(Request $request, AnalyticsService $service)
    {
        $tenant = TenantContext::getTenant();

        $data = $service->getSentimentDistribution(
            $tenant,
            $request->get('date_from'),
            $request->get('date_to')
        );

        return $this->success($data);
    }

    public function volume(Request $request, AnalyticsService $service)
    {
        $tenant = TenantContext::getTenant();

        $data = $service->getVolumeTrends(
            $tenant,
            $request->get('date_from'),
            $request->get('date_to')
        );

        return $this->success($data);
    }

    public function topKeywords(Request $request, AnalyticsService $service)
    {
        $tenant = TenantContext::getTenant();

        $data = $service->getTopKeywords(
            $tenant,
            (int) $request->get('limit', 10)
        );

        return $this->success($data);
    }

    public function topSources(Request $request, AnalyticsService $service)
    {
        $tenant = TenantContext::getTenant();

        $data = $service->getTopSources(
            $tenant,
            (int) $request->get('limit', 10)
        );

        return $this->success($data);
    }
}
