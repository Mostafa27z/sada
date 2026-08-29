<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_user_can_logout_from_all_devices(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        // Create multiple tokens
        $user->createToken('device-1');
        $user->createToken('device-2');
        $user->createToken('device-3');

        $this->assertEquals(3, $user->tokens()->count());

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/logout-all');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(0, $user->tokens()->count());
    }

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_token_is_invalidated_after_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'status' => 'active',
        ]);

        // Login to get a token
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.token');

        // Logout with the token
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Verify the token was deleted from the database
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
