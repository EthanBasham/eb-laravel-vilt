<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\WotAccount;
use App\Models\WotCrewGuide;

/**
 * @extends Factory<WotCrewGuide>
 */
class WotCrewGuideFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
