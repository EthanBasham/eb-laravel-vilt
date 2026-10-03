<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\MonteCarloRun;
use App\Models\User;

/**
 * @extends Factory<MonteCarloRun>
 */
class MonteCarloRunFactory extends Factory
{
    protected $model = MonteCarloRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'runs' => 100,
            'return_volatility' => 12,
            'inflation_volatility' => 1,
            'seed' => 1,
        ];
    }
}
