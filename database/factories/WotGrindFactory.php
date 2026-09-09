<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\WotAccount;
use App\Models\WotGrind;

/**
 * @extends Factory<WotGrind>
 */
class WotGrindFactory extends Factory
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
            'target_type' => WotGrind::TARGET_TANK,
            'target_id' => fake()->unique()->numberBetween(1, 100_000),
            'target_name' => fake()->words(2, true),
            'target_xp' => fake()->numberBetween(10_000, 200_000),
            'baseline_xp' => fake()->numberBetween(0, 500_000),
            'started_at' => now()->subDays(7),
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['completed_at' => now()->subDay()]);
    }
}
