<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_get_profile(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_user_can_update_name(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/me', [
            'name' => 'اسم جديد',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => ['name' => 'اسم جديد'],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'اسم جديد',
        ]);
    }

    public function test_user_can_update_email(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/me', [
            'email' => 'newemail@example.com',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertEquals('newemail@example.com', $user->email);
        // Email verification should be reset
        $this->assertNull($user->email_verified_at);
    }

    public function test_user_cannot_update_email_to_existing_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/me', [
            'email' => 'existing@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => 'oldpassword123',
            'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/me/password', [
            'current_password' => 'oldpassword123',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        // Verify new password works for login
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'newpassword123',
        ])->assertOk();
    }

    public function test_password_change_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'password' => 'oldpassword123',
            'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/me/password', [
            'current_password' => 'wrongpassword',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_suspended_user_is_blocked_by_middleware(): void
    {
        $user = User::factory()->create(['status' => 'suspended']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(403)
            ->assertJson(['success' => false]);
    }
}
