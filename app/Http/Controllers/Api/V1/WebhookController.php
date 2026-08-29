<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Webhooks\IngestArticleWebhookRequest;
use App\Jobs\ProcessIngestedArticleJob;
use App\Models\WebhookLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class WebhookController extends Controller
{
    use ApiResponse;

    /**
     * Ingest articles & AI metadata pushed by external scraping / NLP services.
     */
    public function ingestArticle(IngestArticleWebhookRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $log = WebhookLog::create([
            'event_type' => $payload['event_type'] ?? 'article.ingested',
            'provider' => $payload['provider'] ?? 'external_scraper',
            'payload' => $payload,
            'status' => WebhookLog::STATUS_PENDING,
        ]);

        // Dispatch background queue job for processing & keyword matching
        ProcessIngestedArticleJob::dispatchSync(
            $log->id,
            $payload['tenant_id'],
            $payload['article']
        );

        return $this->success([
            'log_id' => $log->id,
            'status' => 'queued',
        ], __('messages.webhook_received'), 202);
    }
}
