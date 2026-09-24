<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
