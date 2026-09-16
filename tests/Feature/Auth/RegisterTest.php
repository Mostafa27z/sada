<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function validRegistrationData(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'شركة صدى للذكاء الاصطناعي',
            'company_email' => 'contact@sada-ai.com',
            'phone' => '+966500000000',
            'country' => 'المملكة العربية السعودية',
            'industry' => 'الإعلام والذكاء الاصطناعي',
            'website' => 'https://sada-ai.com',
            'name' => 'أحمد محمد',
            'email' => 'ahmed@example.com',
            'password' => 'Password123!@#',
            'password_confirmation' => 'Password123!@#',
            'agree_to_terms' => true,
        ], $overrides);
    }

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationData());

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'status', 'created_at'],
                    'token',
                    'tenant' => ['id', 'name', 'slug'],
                ],
            ])
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'ahmed@example.com',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'شركة صدى للذكاء الاصطناعي',
        ]);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ahmed@example.com']);

        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationData());

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_fails_with_weak_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationData([
            'password' => '123',
            'password_confirmation' => '123',
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_fails_without_password_confirmation(): void
    {
        $data = $this->validRegistrationData();
        unset($data['password_confirmation']);

        $response = $this->postJson('/api/v1/auth/register', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_fails_with_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password', 'company_name']);
    }

    public function test_token_is_returned_on_registration(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationData());

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        // Verify the token works
        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer ' . $token,
        ])->assertOk();
    }
}
