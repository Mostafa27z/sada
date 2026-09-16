<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_can_be_requested(): void
    {
        $user = User::factory()->create(['email' => 'ahmed@example.com']);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'ahmed@example.com',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'ahmed@example.com']);

        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'ahmed@example.com',
            'password' => 'NewPassword123!@#',
            'password_confirmation' => 'NewPassword123!@#',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        // Verify new password works
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ahmed@example.com',
            'password' => 'NewPassword123!@#',
        ])->assertOk();
    }

    public function test_password_reset_fails_with_invalid_token(): void
    {
        User::factory()->create(['email' => 'ahmed@example.com']);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid-token',
            'email' => 'ahmed@example.com',
            'password' => 'NewPassword123!@#',
            'password_confirmation' => 'NewPassword123!@#',
        ]);

        $response->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    public function test_all_tokens_are_revoked_after_password_reset(): void
    {
        $user = User::factory()->create([
            'email' => 'ahmed@example.com',
            'status' => 'active',
        ]);

        // Create some tokens
        $user->createToken('device-1');
        $user->createToken('device-2');
        $this->assertEquals(2, $user->tokens()->count());

        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'ahmed@example.com',
            'password' => 'NewPassword123!@#',
            'password_confirmation' => 'NewPassword123!@#',
        ])->assertOk();

        $this->assertEquals(0, $user->tokens()->count());
    }
}
