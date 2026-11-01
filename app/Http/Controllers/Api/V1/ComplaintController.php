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
        $query = Complaint::query()->with(['assignedUser', 'activities.user', 'location']);

        if ($request->filled('stage') && $request->input('stage') !== 'all') {
            $stageFilter = $request->input('stage');
            if ($stageFilter === 'processing') {
                $query->whereIn('stage', ['processing', 'investigating']);
            } elseif ($stageFilter === 'waiting_beneficiary') {
                $query->whereIn('stage', ['waiting_beneficiary', 'waiting_customer']);
            } else {
                $query->where(function ($q) use ($stageFilter) {
                    $q->where('stage', $stageFilter)->orWhere('status', $stageFilter);
                });
            }
        } elseif ($request->filled('status') && $request->input('status') !== 'all') {
            $statusFilter = $request->input('status');
            if ($statusFilter === 'processing') {
                $query->whereIn('stage', ['processing', 'investigating']);
            } elseif ($statusFilter === 'waiting_beneficiary') {
                $query->whereIn('stage', ['waiting_beneficiary', 'waiting_customer']);
            } else {
                $query->where(function ($q) use ($statusFilter) {
                    $q->where('stage', $statusFilter)->orWhere('status', $statusFilter);
                });
            }
        }

        if ($request->filled('assigned_to') && $request->input('assigned_to') !== 'all') {
            $assignedTo = $request->input('assigned_to');
            if ($assignedTo === 'unassigned') {
                $query->whereNull('assigned_to');
            } elseif ($assignedTo === 'me') {
                $query->where('assigned_to', auth()->id());
            } else {
                $query->where('assigned_to', (int) $assignedTo);
            }
        }

        if ($request->filled('is_analyzed') && $request->input('is_analyzed') !== 'all') {
            $isAnalyzed = $request->input('is_analyzed');
            if ($isAnalyzed === 'analyzed' || $isAnalyzed === '1' || $isAnalyzed === 'true') {
                $query->where('is_analyzed', true);
            } elseif ($isAnalyzed === 'un_analyzed' || $isAnalyzed === '0' || $isAnalyzed === 'false') {
                $query->where('is_analyzed', false);
            }
        }

        if ($request->filled('priority') && $request->input('priority') !== 'all') {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('rate') && $request->input('rate') !== 'all') {
            $query->where('rate', $request->input('rate'));
        }

        if ($request->filled('location_id') && $request->input('location_id') !== 'all') {
            $locationId = $request->input('location_id');
            if ($locationId === 'unassigned') {
                $query->whereNull('tenant_location_id');
            } else {
                $query->where('tenant_location_id', (int) $locationId);
            }
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('opinion', 'like', "%{$search}%")
                  ->orWhere('tracking_number', 'like', "%{$search}%");
                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search);
                }
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
        $unanalyzedCount = Complaint::where('tenant_id', $tenantId)->where('is_analyzed', false)->count();

        if (!$summary) {
            return $this->success([
                'tenant_id' => $tenantId,
                'summary' => 'لا يوجد ملخص لشكاوى العملاء حتى الآن.',
                'recommended_solutions' => [],
                'total_complaints_analyzed' => 0,
                'unanalyzed_count' => $unanalyzedCount,
                'last_complaint_id' => null,
                'updated_at' => null,
            ], 'No complaint summary available');
        }

        return $this->success(new TenantComplaintSummaryResource($summary));
    }

    /**
     * Get complaints statistics and stage distribution for the current tenant.
     *
     * @return JsonResponse
     */
    public function stats(): JsonResponse
    {
        $tenantId = TenantContext::getTenantId();
        $query = Complaint::where('tenant_id', $tenantId);

        $total = (clone $query)->count();
        $newCount = (clone $query)->where('stage', 'new')->count();
        $underReviewCount = (clone $query)->where('stage', 'under_review')->count();
        $contactedCount = (clone $query)->where('stage', 'contacted')->count();
        $processingCount = (clone $query)->whereIn('stage', ['processing', 'investigating'])->count();
        $waitingBeneficiaryCount = (clone $query)->whereIn('stage', ['waiting_beneficiary', 'waiting_customer'])->count();
        $resolvedCount = (clone $query)->where('stage', 'resolved')->count();
        $closedCount = (clone $query)->where('stage', 'closed')->count();
        $escalatedCount = (clone $query)->where('stage', 'escalated')->count();
        $reopenedCount = (clone $query)->where('stage', 'reopened')->count();

        $activePendingCount = $newCount + $underReviewCount + $contactedCount + $processingCount + $waitingBeneficiaryCount + $escalatedCount + $reopenedCount;
        $completedCount = $resolvedCount + $closedCount;

        $resolutionRate = $total > 0 ? round(($completedCount / $total) * 100, 1) : 0;
        $avgRate = (clone $query)->whereNotNull('rate')->avg('rate') ?? 0;
        $avgRate = round((float) $avgRate, 1);

        $priorities = [
            'crisis' => (clone $query)->where('priority', 'crisis')->count(),
            'negative' => (clone $query)->where('priority', 'negative')->count(),
            'neutral' => (clone $query)->where('priority', 'neutral')->count(),
            'positive' => (clone $query)->where('priority', 'positive')->count(),
        ];

        $branches = \App\Models\TenantLocation::where('tenant_id', $tenantId)->get(['id', 'business_name', 'address']);
        $byBranch = [];
        foreach ($branches as $branch) {
            $byBranch[] = [
                'id' => $branch->id,
                'name' => $branch->business_name,
                'address' => $branch->address,
                'count' => (clone $query)->where('tenant_location_id', $branch->id)->count(),
            ];
        }

        return $this->success([
            'total' => $total,
            'active_pending' => $activePendingCount,
            'completed' => $completedCount,
            'resolution_rate' => $resolutionRate,
            'average_rating' => $avgRate,
            'by_stage' => [
                'new' => $newCount,
                'under_review' => $underReviewCount,
                'contacted' => $contactedCount,
                'processing' => $processingCount,
                'waiting_beneficiary' => $waitingBeneficiaryCount,
                'resolved' => $resolvedCount,
                'closed' => $closedCount,
                'escalated' => $escalatedCount,
                'reopened' => $reopenedCount,
            ],
            'by_priority' => $priorities,
            'by_branch' => $byBranch,
        ], 'Complaints statistics calculated successfully');
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
            return $this->error('لا توجد شكاوى مسجلة لهذه المنشأة حتى الآن لتحليلها واستخراج التوصيات', 400);
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
        $complaint = Complaint::with(['assignedUser', 'activities.user', 'location'])->find($id);

        if (!$complaint) {
            return $this->error('Complaint not found', 404);
        }

        return $this->success(new ComplaintResource($complaint));
    }

    /**
     * Assign complaint to a customer support user or unassign.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $complaint = Complaint::with(['assignedUser', 'activities.user'])->find($id);

        if (!$complaint) {
            return $this->error('Complaint not found', 404);
        }

        $userId = $request->input('user_id');
        $oldUser = $complaint->assignedUser;

        if ($userId) {
            $newUser = \App\Models\User::find($userId);
            $complaint->assigned_to = $userId;
            $complaint->is_analyzed = false;
            $complaint->save();

            $complaint->activities()->create([
                'tenant_id' => $complaint->tenant_id,
                'user_id' => auth()->id(),
                'type' => 'assignment',
                'comment' => "تم إسناد التذكرة إلى الممثل: {$newUser->name}",
                'metadata' => [
                    'assigned_to' => $newUser->id,
                    'assigned_to_name' => $newUser->name,
                ],
            ]);
        } else {
            $complaint->assigned_to = null;
            $complaint->is_analyzed = false;
            $complaint->save();

            $complaint->activities()->create([
                'tenant_id' => $complaint->tenant_id,
                'user_id' => auth()->id(),
                'type' => 'assignment',
                'comment' => "تم إلغاء إسناد التذكرة (أصبحت غير معينة)",
                'metadata' => [
                    'previous_assigned_to' => $oldUser?->id,
                ],
            ]);
        }

        $complaint->load(['assignedUser', 'activities.user']);

        return $this->success(new ComplaintResource($complaint), 'تم تحديث تعيين التذكرة بنجاح');
    }

    /**
     * Add an internal note or call log to a complaint and re-evaluate AI recommendation.
     *
     * @param Request $request
     * @param int $id
     * @param ComplaintAiService $aiService
     * @return JsonResponse
     */
    public function addNote(Request $request, int $id, ComplaintAiService $aiService): JsonResponse
    {
        $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
            'type' => ['sometimes', 'string', 'in:note,call_log'],
            'is_visible_to_customer' => ['sometimes', 'boolean'],
        ]);

        $complaint = Complaint::with(['assignedUser', 'activities.user'])->find($id);

        if (!$complaint) {
            return $this->error('Complaint not found', 404);
        }

        $type = $request->input('type', 'note');
        $isVisibleToCustomer = $request->boolean('is_visible_to_customer', false);

        // Create activity log
        $activity = $complaint->activities()->create([
            'tenant_id' => $complaint->tenant_id,
            'user_id' => auth()->id(),
            'type' => $type,
            'comment' => $request->input('comment'),
            'is_visible_to_customer' => $isVisibleToCustomer,
        ]);

        // Track first response time if this is first support interaction
        if (!$complaint->first_responded_at) {
            $complaint->first_responded_at = now();
            if ($complaint->status === 'pending') {
                $complaint->status = 'in_progress';
            }
            if ($complaint->stage === 'new') {
                $complaint->stage = ($type === 'call_log' ? 'contacted' : 'under_review');
            }
        }

        // Marking as unanalyzed so next tenant summary includes this update
        $complaint->is_analyzed = false;
        $complaint->save();

        // Re-analyze single complaint with latest note included so AI factors it into recommendation
        $complaint->load(['assignedUser', 'activities.user']);
        $aiAnalysis = $aiService->analyzeSingleComplaint($complaint);
        $complaint->update([
            'priority' => $aiAnalysis['priority'],
            'ai_recommendation' => $aiAnalysis['ai_recommendation'],
            'is_analyzed' => false,
        ]);

        $complaint->load(['assignedUser', 'activities.user']);

        return $this->success(new ComplaintResource($complaint), 'تم حفظ الملاحظة وإعادة توليد توصية الذكاء الاصطناعي بنجاح');
    }

    /**
     * Update ticket lifecycle stage.
     *
     * @param Request $request
     * @param int $id
     * @param ComplaintAiService $aiService
     * @return JsonResponse
     */
    public function updateStage(Request $request, int $id, ComplaintAiService $aiService): JsonResponse
    {
        $validStages = [
            'new',
            'under_review',
            'contacted',
            'processing',
            'waiting_beneficiary',
            'resolved',
            'closed',
            'escalated',
            'reopened',
            // Legacy fallbacks
            'investigating',
            'waiting_customer',
        ];

        $request->validate([
            'stage' => ['required', 'string', 'in:' . implode(',', $validStages)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $complaint = Complaint::with(['assignedUser', 'activities.user'])->find($id);

        if (!$complaint) {
            return $this->error('Complaint not found', 404);
        }

        $oldStage = $complaint->stage ?? 'new';
        $newStage = $request->input('stage');

        // Normalize legacy stage inputs
        if ($newStage === 'investigating') {
            $newStage = 'processing';
        } elseif ($newStage === 'waiting_customer') {
            $newStage = 'waiting_beneficiary';
        }

        if ($oldStage === $newStage) {
            return $this->success(new ComplaintResource($complaint));
        }

        $stageLabels = [
            'new' => 'جديد',
            'under_review' => 'قيد المراجعة',
            'contacted' => 'تم التواصل مع المستفيد',
            'processing' => 'قيد المعالجة',
            'waiting_beneficiary' => 'بانتظار رد المستفيد',
            'resolved' => 'تم الحل',
            'closed' => 'مغلق',
            'escalated' => 'تم التصعيد',
            'reopened' => 'أعيد فتح الشكوى',
            // Legacy labels
            'investigating' => 'قيد المعالجة',
            'waiting_customer' => 'بانتظار رد المستفيد',
        ];

        $oldLabel = $stageLabels[$oldStage] ?? $oldStage;
        $newLabel = $stageLabels[$newStage] ?? $newStage;

        $complaint->stage = $newStage;
        $complaint->status = $newStage; // Logically identical

        // Automatically align SLA timestamps with stage
        if (in_array($newStage, ['under_review', 'contacted', 'processing', 'waiting_beneficiary', 'escalated'])) {
            if (!$complaint->first_responded_at) {
                $complaint->first_responded_at = now();
            }
        } elseif ($newStage === 'reopened') {
            $complaint->resolved_at = null; // Reopen ticket resets resolved timestamp
            if (!$complaint->first_responded_at) {
                $complaint->first_responded_at = now();
            }
        } elseif (in_array($newStage, ['resolved', 'closed'])) {
            if (!$complaint->resolved_at) {
                $complaint->resolved_at = now();
            }
        }

        $complaint->is_analyzed = false;
        $complaint->save();

        // Create stage change activity log
        $comment = "تم نقل المرحلة من \"{$oldLabel}\" إلى \"{$newLabel}\"";
        if ($request->filled('reason')) {
            $comment .= " - السبب: " . $request->input('reason');
        }

        $complaint->activities()->create([
            'tenant_id' => $complaint->tenant_id,
            'user_id' => auth()->id(),
            'type' => 'stage_change',
            'comment' => $comment,
            'is_visible_to_customer' => true,
            'metadata' => [
                'from_stage' => $oldStage,
                'to_stage' => $newStage,
            ],
        ]);

        // Refresh AI recommendation
        $complaint->load(['assignedUser', 'activities.user']);
        $aiAnalysis = $aiService->analyzeSingleComplaint($complaint);
        $complaint->update([
            'priority' => $aiAnalysis['priority'],
            'ai_recommendation' => $aiAnalysis['ai_recommendation'],
            'is_analyzed' => false,
        ]);

        $complaint->load(['assignedUser', 'activities.user']);

        return $this->success(new ComplaintResource($complaint), "تم تحديث مرحلة التذكرة إلى \"{$newLabel}\" بنجاح");
    }

    /**
     * Trigger on-demand AI reanalysis for a single complaint considering all notes.
     *
     * @param int $id
     * @param ComplaintAiService $aiService
     * @return JsonResponse
     */
    public function reanalyze(int $id, ComplaintAiService $aiService): JsonResponse
    {
        $complaint = Complaint::with(['assignedUser', 'activities.user'])->find($id);

        if (!$complaint) {
            return $this->error('Complaint not found', 404);
        }

        $aiAnalysis = $aiService->analyzeSingleComplaint($complaint);
        $complaint->update([
            'priority' => $aiAnalysis['priority'],
            'ai_recommendation' => $aiAnalysis['ai_recommendation'],
            'is_analyzed' => true,
            'analyzed_at' => now(),
        ]);

        $complaint->load(['assignedUser', 'activities.user']);

        return $this->success(new ComplaintResource($complaint), 'تمت إعادة تحليل الشكوى والملاحظات بنجاح');
    }

    /**
     * List all support agents & managers available for assignment in current tenant.
     *
     * @return JsonResponse
     */
    public function agents(): JsonResponse
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $this->error('Tenant workspace not found', 400);
        }

        $users = TenantContext::withoutTenancy(function () use ($tenant) {
            return $tenant->users()
                ->where(function ($q) {
                    $q->whereNull('tenant_user.role_id')
                      ->orWhereNotIn('tenant_user.role_id', function ($sub) {
                          $sub->select('id')->from('roles')->where('slug', \App\Models\Role::SUPER_ADMIN);
                      });
                })
                ->get()
                ->map(function ($u) {
                    $roleModel = $u->currentRole();
                    $roleSlug = $roleModel?->slug ?? ($u->pivot->is_owner ? 'tenant_owner' : 'member');
                    $isCustomerSupport = ($roleSlug === \App\Models\Role::CUSTOMER_SUPPORT || str_contains($roleSlug, 'support'));
                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'avatar' => $u->avatar,
                        'role' => $u->pivot->is_owner ? 'مالك مساحة العمل' : ($roleModel?->name ?? 'عضو'),
                        'role_slug' => $roleSlug,
                        'is_customer_support' => $isCustomerSupport,
                    ];
                });
        });

        return $this->success($users);
    }

    /**
     * Update complaint status.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $id, ComplaintAiService $aiService): JsonResponse
    {
        $newStage = $request->input('status') ?? $request->input('stage');
        $request->merge(['stage' => $newStage]);
        return $this->updateStage($request, $id, $aiService);
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
