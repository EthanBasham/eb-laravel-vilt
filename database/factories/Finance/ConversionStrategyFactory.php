<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\ConversionStrategy;
use App\Models\User;

/**
 * @extends Factory<ConversionStrategy>
 */
class ConversionStrategyFactory extends Factory
{
    protected $model = ConversionStrategy::class;

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
            'name' => null,
            'kind' => 'none',
            'tax_payment' => 'outside',
            'tax_outside_amount' => null,
            'heir_is_charity' => false,
            'heir_income' => null,
        ];
    }

    /**
     * A kind of strategy, with whatever settings it takes.
     *
     * @param  array<string, mixed>  $settings
     */
    /** Waiting in the holding area rather than being compared. */
    public function held(): static
    {
        return $this->state(['is_compared' => false]);
    }

    public function ofKind(string $kind, array $settings = []): static
    {
        return $this->state(['kind' => $kind, ...$settings]);
    }
}
