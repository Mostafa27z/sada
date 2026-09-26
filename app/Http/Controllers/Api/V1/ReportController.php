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
