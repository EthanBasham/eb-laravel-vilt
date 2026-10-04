<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\Armada;
use App\Models\User;

/**
 * @extends Factory<Armada>
 */
class ArmadaFactory extends Factory
{
    protected $model = Armada::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'description' => null,
        ];
    }
}
