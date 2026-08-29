<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'ulid' => (string) Str::ulid(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(5),
            'logo' => null,
            'status' => Tenant::STATUS_TRIAL,
            'plan_id' => null,
            'settings' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Tenant::STATUS_ACTIVE,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Tenant::STATUS_SUSPENDED,
        ]);
    }
}
