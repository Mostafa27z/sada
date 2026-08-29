<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponseFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_correct_format(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['version', 'timestamp'],
            ])
            ->assertJson([
                'success' => true,
                'data' => ['version' => 'v1'],
            ]);
    }

    public function test_404_returns_json_for_api_routes(): void
    {
        $response = $this->getJson('/api/v1/nonexistent-route');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_401_returns_json_for_protected_routes(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_422_returns_field_errors(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            // Missing all required fields
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => ['name', 'email', 'password'],
            ])
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_api_responses_always_have_json_content_type(): void
    {
        // Even without Accept: application/json header
        $response = $this->get('/api/v1/health');

        $response->assertHeader('Content-Type', 'application/json');
    }
}
