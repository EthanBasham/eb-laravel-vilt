<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\WithdrawalStrategy;
use App\Models\User;

/**
 * @extends Factory<WithdrawalStrategy>
 */
class WithdrawalStrategyFactory extends Factory
{
    protected $model = WithdrawalStrategy::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'scenario_id' => null,
            'name' => fake()->words(2, true),
            'kind' => 'conventional',
            'fill_rate' => null,
            'spending_rule' => 'projection',
            'spending_amount' => null,
            'spending_percent' => null,
        ];
    }

    /**
     * A kind of strategy, with whatever settings it takes.
     *
     * @param  array<string, mixed>  $settings
     */
    public function ofKind(string $kind, array $settings = []): static
    {
        return $this->state(['kind' => $kind, ...$settings]);
    }
}
