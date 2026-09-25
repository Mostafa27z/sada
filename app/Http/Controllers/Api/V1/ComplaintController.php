<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use App\Http\Resources\TenantComplaintSummaryResource;
use App\Models\TenantComplaintSummary;
use App\Services\ComplaintAiService;
use App\Support\TenantContext;

class ComplaintController extends Controller
{
    use ApiResponse;

    /**
     * Get paginated complaints for the current tenant.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Complaint::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('rate')) {
            $query->where('rate', $request->input('rate'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('opinion', 'like', "%{$search}%");
            });
        }

        $complaints = $query->latest()->paginate($request->input('per_page', 20));

        return $this->paginated($complaints, ComplaintResource::class);
    }

    /**
     * Get aggregated AI summary and recommended solutions for the current tenant.
     *
     * @return JsonResponse
     */
    public function summary(): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $summary = TenantComplaintSummary::where('tenant_id', $tenantId)->first();

        if (!$summary) {
            return $this->success([
                'tenant_id' => $tenantId,
                'summary' => 'لا يوجد ملخص لشكاوى العملاء حتى الآن.',
                'recommended_solutions' => [],
                'total_complaints_analyzed' => 0,
                'last_complaint_id' => null,
                'updated_at' => null,
            ], 'No complaint summary available');
        }

        return $this->success(new TenantComplaintSummaryResource($summary));
    }

    /**
     * Manually trigger on-demand AI summary regeneration.
     *
     * @param ComplaintAiService $aiService
     * @return JsonResponse
     */
    public function regenerateSummary(ComplaintAiService $aiService): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $tenant = \App\Models\Tenant::find($tenantId);

        if (!$tenant) {
            return $this->error('Tenant workspace not found', 404);
        }

        $latestComplaint = Complaint::where('tenant_id', $tenantId)->latest()->first();

        if (!$latestComplaint) {
            return $this->error('No complaints recorded for this tenant yet', 400);
        }

        $summary = $aiService->updateTenantSummary($tenant, $latestComplaint);

        return $this->success(new TenantComplaintSummaryResource($summary), 'Tenant complaint summary regenerated successfully');
    }

    /**
     * View a specific complaint by ID.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $complaint = Complaint::find($id);

        if (!$complaint) {
            return $this->error('Complaint not found', 404);
        }

        return $this->success(new ComplaintResource($complaint));
    }

    /**
     * Update complaint status.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:pending,in_progress,resolved,closed'],
        ]);

        $complaint = Complaint::find($id);

        if (!$complaint) {
            return $this->error('Complaint not found', 404);
        }

        $complaint->update([
            'status' => $request->input('status'),
        ]);

        return $this->success(new ComplaintResource($complaint), 'Complaint status updated successfully');
    }

    /**
     * Delete a complaint.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $complaint = Complaint::find($id);

        if (!$complaint) {
            return $this->error('Complaint not found', 404);
        }

        $complaint->delete();

        return $this->success(null, 'Complaint deleted successfully');
    }
}
