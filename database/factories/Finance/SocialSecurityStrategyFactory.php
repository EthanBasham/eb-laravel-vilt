<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\SocialSecurityStrategy;
use App\Models\User;

/**
 * @extends Factory<SocialSecurityStrategy>
 */
class SocialSecurityStrategyFactory extends Factory
{
    protected $model = SocialSecurityStrategy::class;

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
            'claim_age' => 67,
            'claim_months' => 0,
            'spouse_claim_age' => null,
            'spouse_claim_months' => 0,
            'cola_rate' => null,
            'discount_rate' => null,
        ];
    }

    public function claimingAt(int $age, int $months = 0): static
    {
        return $this->state(['claim_age' => $age, 'claim_months' => $months]);
    }
}
