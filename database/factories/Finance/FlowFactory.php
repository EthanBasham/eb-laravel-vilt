<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\Flow;
use App\Models\User;

/**
 * @extends Factory<Flow>
 */
class FlowFactory extends Factory
{
    protected $model = Flow::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'holding_id' => null,
            'direction' => 'expense',
            'category' => 'subscriptions',
            'name' => fake()->words(2, true),
            'amount' => 100,
            'frequency' => 'monthly',
            'annual_growth_rate' => 0,
            'taxation' => null,
            'taxed_portion' => 100,
            'is_essential' => false,
        ];
    }

    public function income(string $category = 'salary'): static
    {
        return $this->state(['direction' => 'income', 'category' => $category, 'taxation' => config("finance.flow_categories.income.{$category}.taxation")]);
    }
}
