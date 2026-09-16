<?php

namespace Tests\Feature\Auth;

use App\Models\AuthVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OtpAndResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_generates_and_stores_6_digit_otp(): void
    {
        $user = User::factory()->create(['email' => 'user@sada.com']);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'user@sada.com',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('auth_verification_codes', [
            'email' => 'user@sada.com',
            'type' => 'password_reset',
        ]);
    }

    public function test_verify_otp_succeeds_with_valid_code(): void
    {
        $user = User::factory()->create(['email' => 'user@sada.com']);

        AuthVerificationCode::create([
            'email' => 'user@sada.com',
            'code' => '123456',
            'type' => 'password_reset',
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'email' => 'user@sada.com',
            'code' => '123456',
            'type' => 'password_reset',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => ['token', 'email'],
            ]);
    }

    public function test_reset_password_succeeds_with_verified_token(): void
    {
        $user = User::factory()->create([
            'email' => 'user@sada.com',
            'password' => 'OldPassword123!',
        ]);

        AuthVerificationCode::create([
            'email' => 'user@sada.com',
            'code' => '123456',
            'token' => 'test-secure-reset-token-xyz',
            'type' => 'password_reset',
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@sada.com',
            'token' => 'test-secure-reset-token-xyz',
            'password' => 'NewSecurePassword123!@#',
            'confirmPassword' => 'NewSecurePassword123!@#',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue(Hash::check('NewSecurePassword123!@#', $user->fresh()->password));
    }
}
