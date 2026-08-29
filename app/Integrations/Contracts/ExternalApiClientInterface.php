<?php

namespace App\Integrations\Contracts;

interface ExternalApiClientInterface
{
    /**
     * Trigger an asynchronous web scraping job on external scraper service.
     */
    public function triggerScrape(array $sources, array $options = []): array;

    /**
     * Request external NLP service to analyze sentiment of text.
     */
    public function analyzeSentiment(string $text): array;

    /**
     * Request external AI service to generate a summary.
     */
    public function summarizeArticle(string $text): string;

    /**
     * Request external AI service to extract named entities.
     */
    public function extractEntities(string $text): array;
}
