<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Milestone;
use App\Models\Project;

/**
 * @extends Factory<Milestone>
 */
class MilestoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(5),
            'notes' => fake()->boolean(40) ? fake()->sentence(15) : null,
            'sort_order' => fake()->numberBetween(0, 20),
            'completed_at' => null,
        ];
    }

    public function complete(): static
    {
        return $this->state(fn (): array => [
            'completed_at' => fake()->dateTimeBetween('-6 months', '-1 day'),
        ]);
    }
}
