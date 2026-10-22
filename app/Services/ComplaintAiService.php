<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\Tenant;
use App\Models\TenantComplaintSummary;
use Illuminate\Support\Facades\Log;

class ComplaintAiService extends GeminiAnalyticsService
{
    /**
     * Analyze a single complaint to detect priority/severity and generate a handling recommendation.
     *
     * @param Complaint $complaint
     * @return array{priority: string, ai_recommendation: string}
     */
    public function analyzeSingleComplaint(Complaint $complaint): array
    {
        $customerName = !empty($complaint->name) ? $complaint->name : 'عميل غير مسجل (مجهول)';
        $assignedAgent = $complaint->assignedUser?->name ?? 'غير معين';
        $currentStage = $complaint->stage ?? 'new';

        // Load internal support activities & notes
        $supportNotesText = '';
        if ($complaint->relationLoaded('activities') || $complaint->id) {
            $activities = $complaint->activities()
                ->with('user:id,name')
                ->oldest()
                ->get();

            if ($activities->isNotEmpty()) {
                $supportNotesText = $activities->map(function ($act) {
                    $author = $act->user?->name ?? 'فريق الدعم';
                    $typeLabel = match ($act->type) {
                        'call_log' => 'مكالمة هاتفية',
                        'stage_change' => 'تغيير مرحلة',
                        'assignment' => 'إسناد تذكرة',
                        default => 'ملاحظة داخلية',
                    };
                    return "- [{$typeLabel} بواسطة {$author}]: {$act->comment}";
                })->implode("\n");
            }
        }

        if (empty($supportNotesText)) {
            $supportNotesText = 'لا توجد ملاحظات دعم مسجلة حتى الآن.';
        }

        $prompt = "You are a Senior Customer Relations & CX Intelligence AI Specialist for a business.
Analyze the following customer ticket, taking into full account the customer's sentiment, current stage, and the support team's internal notes and progress:

CUSTOMER FEEDBACK:
- Customer Name: {$customerName}
- Rating: {$complaint->rate}/5
- Message/Opinion: \"{$complaint->opinion}\"

INTERNAL SUPPORT & TICKET PROGRESS:
- Ticket Stage: {$currentStage}
- Assigned Support Agent: {$assignedAgent}
- Support Notes & Actions Taken:
{$supportNotesText}

Evaluate:
1. priority: Must be strictly one of ['positive', 'neutral', 'negative', 'crisis'].
   - 'positive': Customer is happy or praising.
   - 'neutral': General inquiry or minor suggestion.
   - 'negative': Customer is dissatisfied or complaining.
   - 'crisis': Severe operational failure, legal threat, harassment, food poisoning, or extreme urgency.
2. ai_recommendation: Precise, actionable next-step recommendation for the customer support representative ({$assignedAgent}), reflecting both the customer's problem AND the internal notes/actions logged so far.
   IMPORTANT: The `ai_recommendation` field MUST BE WRITTEN IN CLEAR, PROFESSIONAL ARABIC (اللغة العربية).

Return valid JSON strictly matching:
{
  \"priority\": \"positive|neutral|negative|crisis\",
  \"ai_recommendation\": \"نص التوصية باللغة العربية مع الأخذ بعين الاعتبار ملاحظات وإجراءات فريق الدعم\"
}";

        $messages = [
            ['role' => 'system', 'content' => 'You analyze customer complaints and customer support progress to produce insightful Arabic recommendations.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $rawJson = $this->callGemini($messages, 45);
            if ($rawJson) {
                $parsed = json_decode($rawJson, true);
                if (is_array($parsed) && isset($parsed['priority'], $parsed['ai_recommendation'])) {
                    $validPriorities = ['positive', 'neutral', 'negative', 'crisis'];
                    $priority = in_array(strtolower($parsed['priority']), $validPriorities) ? strtolower($parsed['priority']) : 'neutral';
                    return [
                        'priority' => $priority,
                        'ai_recommendation' => (string) $parsed['ai_recommendation'],
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error("ComplaintAiService::analyzeSingleComplaint error: " . $e->getMessage());
        }

        // Fallback rule-based defaults if AI call fails — dynamic based on complaint content
        $fallbackPriority = match (true) {
            $complaint->rate >= 4 => 'positive',
            $complaint->rate === 3 => 'neutral',
            $complaint->rate <= 2 => 'negative',
            default => 'neutral',
        };

        // Build a contextual recommendation based on rating, complaint text, and notes
        $opinion = mb_substr(trim($complaint->opinion ?? ''), 0, 80);
        $name    = $complaint->name ?? 'العميل';

        $fallbackRecommendation = match (true) {
            $complaint->rate >= 4 => "التواصل مع {$name} لشكره على تقييمه الإيجابي وتعزيز ولائه." . ($opinion ? " استجب على تعليقه: \"{$opinion}\" بمحتوى مخصص." : ''),
            $complaint->rate === 3 => "التواصل مع {$name} لفهم ما يمكن تحسينه ومتابعة الملاحظات المدونة." . ($opinion ? " تعليقه: \"{$opinion}\" يستحق متابعة دقيقة." : ''),
            $complaint->rate === 2 => "الاتصال الفوري بـ{$name} ومراجعة ملاحظات موظف الدعم للتوصل لحل مُرضٍ." . ($opinion ? " المشكلة: \"{$opinion}\"." : ''),
            $complaint->rate <= 1  => "تصعيد الشكوى فوراً لمدير الدعم والاتصال بـ{$name} مع اعتماد تعويض عاجل بناءً على تقرير الدعم." . ($opinion ? " الشكوى: \"{$opinion}\"." : ''),
            default => "متابعة الملاحظات المدونة والتواصل مع {$name} عبر الهاتف أو البريد الإلكتروني.",
        };

        return [
            'priority' => $fallbackPriority,
            'ai_recommendation' => $fallbackRecommendation,
        ];
    }

    /**
     * Incrementally update the AI summary and recommended solutions for a tenant,
     * incorporating customer support notes and operational stage progress.
     *
     * @param Tenant $tenant
     * @param Complaint $latestComplaint
     * @return TenantComplaintSummary
     */
    public function updateTenantSummary(Tenant $tenant, ?Complaint $latestComplaint = null): TenantComplaintSummary
    {
        $existing = TenantComplaintSummary::where('tenant_id', $tenant->id)->first();

        $previousSummaryText = $existing?->summary ?? 'لا يوجد ملخص سابق مسجل.';
        $previousSolutions = is_array($existing?->recommended_solutions) ? json_encode($existing->recommended_solutions, JSON_UNESCAPED_UNICODE) : 'لا يوجد';

        // 1. Gather all UNANALYZED or updated complaints that require analysis
        $unanalyzedComplaints = Complaint::where('tenant_id', $tenant->id)
            ->where('is_analyzed', false)
            ->with(['assignedUser:id,name', 'activities' => function ($q) {
                $q->latest()->limit(5);
            }])
            ->latest()
            ->get();

        // 2. Build the context for analysis
        if ($unanalyzedComplaints->isNotEmpty()) {
            $complaintsContext = "NEW OR UPDATED COMPLAINTS WAITING FOR ANALYSIS (شكاوى جديدة أو تم تحديثها بملاحظات/مراحل تتطلب التحليل):\n";
            $complaintsContext .= $unanalyzedComplaints->map(function ($c) {
                $agent = $c->assignedUser?->name ?? 'غير معين';
                $notes = $c->activities->pluck('comment')->filter()->join(' | ');
                $notesSnippet = $notes ? " [ملاحظات وإجراءات الدعم: {$notes}]" : " [لم تُدون ملاحظات]";
                return "- شكوى من {$c->name} (تقييم: {$c->rate}/5، مرحلة: {$c->stage}، مسؤول: {$agent}): \"{$c->opinion}\"{$notesSnippet}";
            })->implode("\n");
        } else {
            // Fallback: If no unanalyzed, take recent complaints for full re-analysis
            $recent = Complaint::where('tenant_id', $tenant->id)
                ->with(['assignedUser:id,name', 'activities' => function ($q) {
                    $q->latest()->limit(3);
                }])
                ->latest()
                ->limit(8)
                ->get();

            $complaintsContext = "RECENT COMPLAINTS CONTEXT:\n";
            $complaintsContext .= $recent->map(function ($c) {
                $agent = $c->assignedUser?->name ?? 'غير معين';
                $notes = $c->activities->pluck('comment')->filter()->join(' | ');
                $notesSnippet = $notes ? " [ملاحظات وإجراءات الدعم: {$notes}]" : " [لم تُدون ملاحظات]";
                return "- شكوى من {$c->name} (تقييم: {$c->rate}/5، مرحلة: {$c->stage}، مسؤول: {$agent}): \"{$c->opinion}\"{$notesSnippet}";
            })->implode("\n");
        }

        $prompt = "You are a Chief Customer Experience & Operations Officer analyzing customer feedback and internal support performance for company '{$tenant->name}'.

EXISTING EXECUTIVE SUMMARY (ملخص سابق):
\"{$previousSummaryText}\"

PREVIOUS SOLUTIONS (حلول سابقة):
{$previousSolutions}

COMPLAINTS FEEDBACK AND SUPPORT ACTIONS:
{$complaintsContext}

Tasks:
1. Update the overall tenant complaints summary incrementally in Arabic (اللغة العربية).
   IMPORTANT: Integrate both the customer feedback AND the internal customer support notes to uncover true operational root causes (أسباب المشاكل التشغيلية الحقيقية من واقع ملاحظات خدمة العملاء) and support team responsiveness.
2. Provide 3 to 5 clear, actionable strategic solutions for management written in Arabic (اللغة العربية).

IMPORTANT: Both `summary` and `recommended_solutions` MUST BE WRITTEN ENTIRELY IN ARABIC (اللغة العربية).

Return valid JSON strictly matching:
{
  \"summary\": \"ملخص تنفيذي شامل باللغة العربية يربط بين شكاوى العملاء وملاحظات فريق الدعم الفني\",
  \"recommended_solutions\": [
    \"الحل التنفيذي الأول باللغة العربية\",
    \"الحل التنفيذي الثاني باللغة العربية\",
    \"الحل التنفيذي الثالث باللغة العربية\"
  ]
}";

        $messages = [
            ['role' => 'system', 'content' => 'You provide executive-level customer experience and operations root-cause summaries in Arabic.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        $summaryText = $previousSummaryText;
        $recommendedSolutions = is_array($existing?->recommended_solutions) ? $existing->recommended_solutions : [];

        try {
            $rawJson = $this->callGemini($messages, 60);
            if ($rawJson) {
                $parsed = json_decode($rawJson, true);
                if (is_array($parsed)) {
                    if (!empty($parsed['summary'])) {
                        $summaryText = (string) $parsed['summary'];
                    }
                    if (isset($parsed['recommended_solutions']) && is_array($parsed['recommended_solutions'])) {
                        $recommendedSolutions = array_map('strval', $parsed['recommended_solutions']);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("ComplaintAiService::updateTenantSummary error: " . $e->getMessage());
        }

        $totalAnalyzed = ($existing?->total_complaints_analyzed ?? 0) + ($unanalyzedComplaints->count() ?: 1);
        $lastComplaintId = $latestComplaint?->id ?? Complaint::where('tenant_id', $tenant->id)->latest()->value('id');

        // Mark all unanalyzed complaints as analyzed now
        Complaint::where('tenant_id', $tenant->id)
            ->where('is_analyzed', false)
            ->update([
                'is_analyzed' => true,
                'analyzed_at' => now(),
            ]);

        return TenantComplaintSummary::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'summary' => $summaryText,
                'recommended_solutions' => $recommendedSolutions,
                'total_complaints_analyzed' => $totalAnalyzed,
                'last_complaint_id' => $lastComplaintId,
            ]
        );
    }
}
