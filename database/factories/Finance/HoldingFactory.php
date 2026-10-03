<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\Holding;
use App\Models\User;

/**
 * @extends Factory<Holding>
 */
class HoldingFactory extends Factory
{
    protected $model = Holding::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'side' => 'asset',
            'type' => 'brokerage',
            'name' => fake()->company().' account',
            'institution' => null,
            'balance' => 10000,
            'annual_rate' => 7,
            'monthly_contribution' => 0,
        ];
    }

    public function liability(string $type = 'mortgage'): static
    {
        return $this->state(['side' => 'liability', 'type' => $type]);
    }

    public function retirement(string $taxType = 'traditional', string $planType = '401k'): static
    {
        return $this->state(['side' => 'asset', 'type' => 'retirement', 'plan_type' => $planType, 'tax_type' => $taxType]);
    }

    /** An account held inside another, owned by the same user. */
    public function inside(Holding $parent): static
    {
        return $this->state(['user_id' => $parent->user_id, 'parent_id' => $parent->id]);
    }

    public function ofType(string $type): static
    {
        return $this->state(['side' => config("finance.holding_types.{$type}.side"), 'type' => $type]);
    }
}
