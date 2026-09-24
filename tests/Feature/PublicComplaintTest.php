<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicComplaintTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_generate_qr_code_svg_image(): void
    {
        $response = $this->getJson('/api/v1/public/qr-code?url=https://example.com/complaint?tenant_id=1');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_can_generate_qr_code_json_base64(): void
    {
        $response = $this->getJson('/api/v1/public/qr-code?url=https://example.com/complaint?tenant_id=1&format=json');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'url',
                'qr_code',
            ],
        ]);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('data.qr_code'));
    }

    public function test_can_submit_complaint_successfully(): void
    {
        $tenant = Tenant::factory()->create();

        $payload = [
            'tenant_id' => $tenant->id,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+1234567890',
            'opinion' => 'The service was delayed, please resolve this issue.',
        ];

        $response = $this->postJson('/api/v1/public/complaints', $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Complaint submitted successfully',
            'data' => [
                'tenant_id' => $tenant->id,
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'phone' => '+1234567890',
                'opinion' => 'The service was delayed, please resolve this issue.',
                'status' => 'pending',
            ],
        ]);

        $this->assertDatabaseHas('complaints', [
            'tenant_id' => $tenant->id,
            'email' => 'john@example.com',
            'opinion' => 'The service was delayed, please resolve this issue.',
        ]);
    }

    public function test_complaint_submission_fails_with_invalid_data(): void
    {
        $response = $this->postJson('/api/v1/public/complaints', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tenant_id', 'name', 'email', 'opinion']);
    }

    public function test_tenant_can_list_their_complaints(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        $user->tenants()->attach($tenant->id);

        Complaint::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'phone' => '123456',
            'opinion' => 'Bad experience with support.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->getJson('/api/v1/complaints');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'tenant_id', 'name', 'email', 'phone', 'opinion', 'status', 'created_at'],
            ],
            'meta' => ['current_page', 'total'],
        ]);
    }

    public function test_tenant_can_update_complaint_status(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'status' => 'active',
        ]);
        $user->tenants()->attach($tenant->id);

        $complaint = Complaint::create([
            'tenant_id' => $tenant->id,
            'name' => 'Alice Brown',
            'email' => 'alice@example.com',
            'opinion' => 'Needs follow up.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->patchJson("/api/v1/complaints/{$complaint->id}/status", [
                'status' => 'resolved',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'resolved');
        $this->assertDatabaseHas('complaints', [
            'id' => $complaint->id,
            'status' => 'resolved',
        ]);
    }
}
