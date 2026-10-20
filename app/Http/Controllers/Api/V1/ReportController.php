<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use App\Services\ReportGeneratorService;
use App\Support\ApiResponse;

class ReportController extends Controller
{
    use ApiResponse;

    public function index(ReportGeneratorService $generator)
    {
        // Reports are generated only on-demand by user action, not automatically on page load.
        $query = Report::latest();

        if (request()->filled('search')) {
            $s = trim((string) request('search'));
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('type', 'like', "%{$s}%");
            });
        }

        $reports = $query->paginate(request('per_page', 20));

        return $this->paginated($reports, ReportResource::class);
    }

    public function targets(): \Illuminate\Http\JsonResponse
    {
        $tenantId = \App\Support\TenantContext::getTenantId();
        if (!$tenantId) {
            $tenantId = auth()->user()?->current_tenant_id ?: auth()->user()?->currentTenant?->id;
        }
        if (!$tenantId) {
            $tenantId = \App\Models\Tenant::first()?->id;
        }

        // 1. Radar news items grouped by batch_date and created_at
        $radarItems = \App\Models\TenantIndustryNews::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->select('batch_date', \Illuminate\Support\Facades\DB::raw('count(*) as count'), \Illuminate\Support\Facades\DB::raw('MAX(created_at) as created_at'))
            ->groupBy('batch_date')
            ->orderBy('batch_date', 'desc')
            ->get()
            ->map(function ($row) {
                $cleanDate = substr((string) $row->batch_date, 0, 10);
                $cAt = $row->created_at ? \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Riyadh') : null;
                $formattedTime = $cAt ? $cAt->translatedFormat('Y-m-d - h:i a') : $cleanDate;
                $timeOnly = $cAt ? $cAt->translatedFormat('h:i a') : '';
                return [
                    'value' => $cleanDate,
                    'batch_date' => $cleanDate,
                    'created_at' => (string) $row->created_at,
                    'formatted_time' => $formattedTime,
                    'time_only' => $timeOnly,
                    'count' => (int) $row->count,
                    'label' => "رصد أخبار {$cleanDate}" . ($timeOnly ? " - الساعة {$timeOnly}" : "") . " - ({$row->count} خبر)",
                ];
            });

        // 2. Campaigns
        $campaigns = \App\Models\Collection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->withCount('articles')
            ->latest()
            ->get()
            ->map(function ($col) {
                $cAt = $col->created_at ? \Carbon\Carbon::parse($col->created_at)->timezone('Asia/Riyadh') : null;
                $timeStr = $cAt ? $cAt->translatedFormat('Y-m-d - h:i a') : '';
                return [
                    'value' => (string) $col->id,
                    'id' => (string) $col->id,
                    'name' => $col->name,
                    'created_at' => (string) $col->created_at,
                    'formatted_time' => $timeStr,
                    'count' => (int) ($col->articles_count ?? 0),
                    'label' => "{$col->name}" . ($timeStr ? " ({$timeStr})" : "") . " - ({$col->articles_count} مادة)",
                ];
            });

        // 3. Complaints grouped by date
        $complaintDates = \App\Models\Complaint::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->select(
                \Illuminate\Support\Facades\DB::raw('DATE(created_at) as date'),
                \Illuminate\Support\Facades\DB::raw('count(*) as count'),
                \Illuminate\Support\Facades\DB::raw('MAX(created_at) as created_at')
            )
            ->groupBy(\Illuminate\Support\Facades\DB::raw('DATE(created_at)'))
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($row) {
                $cAt = $row->created_at ? \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Riyadh') : null;
                $today = now('Asia/Riyadh')->toDateString();
                $yesterday = now('Asia/Riyadh')->subDay()->toDateString();
                $dayLabel = $row->date === $today ? 'اليوم' : ($row->date === $yesterday ? 'الأمس' : $row->date);
                $timeOnly = $cAt ? $cAt->translatedFormat('h:i a') : '';
                return [
                    'value' => (string) $row->date,
                    'date' => (string) $row->date,
                    'created_at' => (string) $row->created_at,
                    'count' => (int) $row->count,
                    'label' => "شكاوى {$dayLabel} ({$row->date})" . ($timeOnly ? " - الساعة {$timeOnly}" : "") . " - ({$row->count} شكوى)",
                ];
            });

        return $this->success([
            'radar' => $radarItems,
            'campaigns' => $campaigns,
            'complaints' => $complaintDates,
        ]);
    }

    protected function ensureDynamicReports(\App\Models\Tenant $tenant, ReportGeneratorService $generator): void
    {
        \App\Support\TenantContext::withoutTenancy(function () use ($tenant, $generator) {
            $radarBatchDates = \App\Models\TenantIndustryNews::where('tenant_id', $tenant->id)
                ->distinct()
                ->pluck('batch_date');

            foreach ($radarBatchDates as $rawDate) {
                $bDate = is_string($rawDate) ? substr($rawDate, 0, 10) : $rawDate;
                if (!$bDate) continue;

                $exists = Report::where('tenant_id', $tenant->id)
                    ->where('type', 'radar')
                    ->where(function ($q) use ($bDate) {
                        $q->where('parameters->batch_date', $bDate)
                          ->orWhere('name', 'like', "%{$bDate}%");
                    })
                    ->exists();

                if (!$exists) {
                    try {
                        $generator->generateRadarReport($tenant, $bDate);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("Could not auto-generate radar report for {$bDate}: " . $e->getMessage());
                    }
                }
            }

            $hasComplaints = \App\Models\Complaint::where('tenant_id', $tenant->id)->exists();
            if ($hasComplaints) {
                $complaintsReportExists = Report::where('tenant_id', $tenant->id)
                    ->where('type', 'complaints')
                    ->exists();

                if (!$complaintsReportExists) {
                    try {
                        $generator->generateComplaintsReport($tenant);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("Could not auto-generate complaints report: " . $e->getMessage());
                    }
                }
            }
        });
    }

    public function store(StoreReportRequest $request, ReportGeneratorService $generator)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $report = Report::create($data);

        // Synchronously generate report file for MVP
        $generator->generate($report);

        return $this->created(new ReportResource($report->fresh()));
    }

    public function show(int $id)
    {
        $report = Report::find($id);

        if (!$report) {
            return $this->error(__('messages.not_found'), 404);
        }

        return $this->success(new ReportResource($report));
    }

    public function download(int $id, ReportGeneratorService $generator)
    {
        $report = Report::find($id);

        if (!$report) {
            return $this->error(__('messages.not_found'), 404);
        }

        $publicDisk = \Illuminate\Support\Facades\Storage::disk('public');
        if (!$report->file_path || !$publicDisk->exists($report->file_path)) {
            $generator->generate($report);
            $report = $report->fresh();
        }

        $fullPath = $publicDisk->path($report->file_path);
        $downloadName = \Illuminate\Support\Str::slug($report->name) . '.html';

        return response()->download($fullPath, $downloadName, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function destroy(int $id)
    {
        $report = Report::find($id);

        if (!$report) {
            return $this->error(__('messages.not_found'), 404);
        }

        if ($report->file_path) {
            $publicDisk = \Illuminate\Support\Facades\Storage::disk('public');
            if ($publicDisk->exists($report->file_path)) {
                $publicDisk->delete($report->file_path);
            }
        }

        $report->delete();

        return $this->success(null, __('messages.deleted'));
    }
}
