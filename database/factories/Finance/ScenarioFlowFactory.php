<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\Flow;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;

/**
 * @extends Factory<ScenarioFlow>
 */
class ScenarioFlowFactory extends Factory
{
    protected $model = ScenarioFlow::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'flow_id' => Flow::factory(),
            'annual_growth_rate' => null,
            'overrides' => null,
            'restarts' => null,
        ];
    }
}
