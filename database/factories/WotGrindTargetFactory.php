<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\WotAccount;
use App\Models\WotGrindTarget;

/**
 * @extends Factory<WotGrindTarget>
 */
class WotGrindTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wot_account_id' => WotAccount::factory(),
            'tank_id' => fake()->unique()->numberBetween(1, 100_000),
            'sort_order' => 0,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['completed_at' => now()]);
    }
}
