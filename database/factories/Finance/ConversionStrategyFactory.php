<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\ConversionReportEntry;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Scenario;
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
            'name' => null,
            'kind' => 'none',
            'tax_payment' => 'outside',
            'tax_outside_amount' => null,
            'heir_is_charity' => false,
            'heir_income' => null,
        ];
    }

    /**
     * In the report: on the projection given, or on the income and expenses
     * as entered. A strategy is in no report until it is put in one.
     */
    public function reported(?Scenario $scenario = null): static
    {
        return $this->has(ConversionReportEntry::factory()->state(['scenario_id' => $scenario?->id]), 'reportEntries');
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
