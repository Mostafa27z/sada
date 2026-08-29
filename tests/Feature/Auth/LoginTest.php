<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'status'],
                    'token',
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ahmed@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'status' => 'inactive',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_last_login_at_is_updated_on_login(): void
    {
        $user = User::factory()->create([
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'status' => 'active',
            'last_login_at' => null,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
    }

    public function test_token_returned_on_login_is_functional(): void
    {
        User::factory()->create([
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $token = $response->json('data.token');

        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk()
            ->assertJson(['success' => true]);
    }
}
