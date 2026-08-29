<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'أحمد محمد',
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'status', 'created_at'],
                    'token',
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'ahmed@example.com',
            'status' => 'active',
        ]);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ahmed@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'أحمد محمد',
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_fails_with_weak_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'أحمد محمد',
            'email' => 'ahmed@example.com',
            'password' => '123',
            'password_confirmation' => '123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_fails_without_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'أحمد محمد',
            'email' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_fails_with_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_token_is_returned_on_registration(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'أحمد محمد',
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        // Verify the token works
        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk();
    }
}
