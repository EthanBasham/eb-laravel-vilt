<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\WotVehicle;

/**
 * @extends Factory<WotVehicle>
 */
class WotVehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->bothify('T-##?');

        return [
            'tank_id' => fake()->unique()->numberBetween(1, 100_000),
            'name' => $name,
            'short_name' => $name,
            'tier' => fake()->numberBetween(1, 10),
            'nation' => fake()->randomElement(['ussr', 'germany', 'usa', 'france', 'uk', 'china', 'japan']),
            'type' => fake()->randomElement(['heavyTank', 'mediumTank', 'lightTank', 'AT-SPG', 'SPG']),
            'is_premium' => false,
            'image_url' => null,
        ];
    }

    public function premium(): static
    {
        return $this->state(fn (): array => ['is_premium' => true]);
    }
}
