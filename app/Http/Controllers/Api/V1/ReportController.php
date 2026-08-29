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

    public function index()
    {
        $reports = Report::latest()->paginate(request('per_page', 20));

        return $this->paginated($reports, ReportResource::class);
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

        if (!$report->file_path || !file_exists(storage_path('app/' . $report->file_path))) {
            $generator->generate($report);
            $report = $report->fresh();
        }

        return response()->download(storage_path('app/' . $report->file_path));
    }
}
