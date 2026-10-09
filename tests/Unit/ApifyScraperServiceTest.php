<?php

namespace Tests\Unit;

use App\Services\ApifyScraperService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApifyScraperServiceTest extends TestCase
{
    public function test_input_keywords_are_deduplicated_before_sending_to_apify(): void
    {
        $sentInput = null;
        Http::fake([
            'https://api.apify.com/v2/acts/*' => function ($request) use (&$sentInput) {
                $sentInput = $request->data();
                return Http::response([
                    'data' => ['defaultDatasetId' => 'test-dataset-id']
                ], 201);
            },
            'https://api.apify.com/v2/datasets/*' => Http::response([], 200),
        ]);

        $service = new class extends ApifyScraperService {
            public function __construct() {
                $this->token = 'dummy-token';
                $this->baseUrl = 'https://api.apify.com/v2';
            }
            public function testRunActor(string $actorId, array $input): array {
                return $this->runActorAndFetchItems($actorId, $input);
            }
        };

        $service->testRunActor('8CiMefkv2yLlD7vYl', [
            'keywords' => ['"شركة سدا"', 'شركة سدا للحلول', '"شركة سدا"', ''],
            'maxItems' => 10,
        ]);

        $this->assertNotNull($sentInput);
        $this->assertEquals(['"شركة سدا"', 'شركة سدا للحلول'], $sentInput['keywords']);
        $this->assertEquals(count($sentInput['keywords']), count(array_unique($sentInput['keywords'])));
    }
}
