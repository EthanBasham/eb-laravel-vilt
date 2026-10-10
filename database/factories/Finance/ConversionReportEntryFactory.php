<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\ConversionReportEntry;
use App\Models\Finance\ConversionStrategy;

/**
 * @extends Factory<ConversionReportEntry>
 */
class ConversionReportEntryFactory extends Factory
{
    protected $model = ConversionReportEntry::class;

    /**
     * Define the model's default state: a strategy of its own, run on the
     * income and expenses as entered, and belonging to whoever owns it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversion_strategy_id' => ConversionStrategy::factory(),
            'user_id' => fn (array $attributes): int => ConversionStrategy::query()->findOrFail($attributes['conversion_strategy_id'])->user_id,
            'scenario_id' => null,
        ];
    }
}
