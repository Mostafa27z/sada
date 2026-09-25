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
        $prompt = "You are an AI customer relations assistant for a business.
Analyze the following customer complaint/feedback:
- Customer Name: {$complaint->name}
- Rating: {$complaint->rate}/5
- Message/Opinion: \"{$complaint->opinion}\"

Evaluate:
1. priority: Must be strictly one of ['positive', 'neutral', 'negative', 'crisis'].
   - 'positive': Customer is happy or praising (e.g. rate 4-5 with good feedback).
   - 'neutral': General inquiry or minor suggestion.
   - 'negative': Customer is dissatisfied or complaining (e.g. rate 1-2 or negative tone).
   - 'crisis': Severe operational failure, legal threat, harassment, or extreme urgency.
2. ai_recommendation: Concise, step-by-step recommendation for the customer support team on how to handle this specific client (e.g., offer a refund, call directly within 1 hour, thank the user, escalate to manager).

Return valid JSON strictly matching:
{
  \"priority\": \"positive|neutral|negative|crisis\",
  \"ai_recommendation\": \"string\"
}";

        $messages = [
            ['role' => 'system', 'content' => 'You analyze customer feedback and return structured JSON.'],
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

        // Fallback rule-based defaults if AI call fails
        $fallbackPriority = match (true) {
            $complaint->rate >= 4 => 'positive',
            $complaint->rate === 3 => 'neutral',
            $complaint->rate <= 2 => 'negative',
            default => 'neutral',
        };

        return [
            'priority' => $fallbackPriority,
            'ai_recommendation' => 'Contact customer via email/phone to acknowledge feedback and address concerns.',
        ];
    }

    /**
     * Incrementally update the AI summary and recommended solutions for a tenant.
     *
     * @param Tenant $tenant
     * @param Complaint $latestComplaint
     * @return TenantComplaintSummary
     */
    public function updateTenantSummary(Tenant $tenant, Complaint $latestComplaint): TenantComplaintSummary
    {
        $existing = TenantComplaintSummary::where('tenant_id', $tenant->id)->first();

        $previousSummaryText = $existing?->summary ?? 'No previous summary recorded.';
        $previousSolutions = is_array($existing?->recommended_solutions) ? json_encode($existing->recommended_solutions, JSON_UNESCAPED_UNICODE) : 'None';

        $prompt = "You are a Chief Customer Experience Officer analyzing feedback for tenant '{$tenant->name}'.

We have an EXISTING SUMMARY of company complaints:
\"{$previousSummaryText}\"

PREVIOUS RECOMMENDED SOLUTIONS:
{$previousSolutions}

A NEW COMPLAINT HAS BEEN SUBMITTED:
- Customer: {$latestComplaint->name} ({$latestComplaint->email})
- Rating: {$latestComplaint->rate}/5
- Priority: {$latestComplaint->priority}
- Customer Feedback: \"{$latestComplaint->opinion}\"

Tasks:
1. Update the overall tenant complaints summary incrementally, keeping track of main recurring issues, customer sentiment trends, and common customer dissatisfaction points.
2. Provide 3 to 5 clear, high-level action recommendations for management to fix root causes across the company.

Return valid JSON strictly matching:
{
  \"summary\": \"Text summary of overall tenant complaint patterns and trends\",
  \"recommended_solutions\": [
    \"Actionable solution 1\",
    \"Actionable solution 2\",
    \"Actionable solution 3\"
  ]
}";

        $messages = [
            ['role' => 'system', 'content' => 'You provide executive-level customer experience summaries in JSON.'],
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

        $totalAnalyzed = ($existing?->total_complaints_analyzed ?? 0) + 1;

        return TenantComplaintSummary::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'summary' => $summaryText,
                'recommended_solutions' => $recommendedSolutions,
                'total_complaints_analyzed' => $totalAnalyzed,
                'last_complaint_id' => $latestComplaint->id,
            ]
        );
    }
}
