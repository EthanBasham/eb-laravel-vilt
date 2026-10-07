<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Scenario;

/**
 * One conversion strategy of each kind, made in one go: for a single
 * projection, or for every projection the user has.
 */
class StoreStarterStrategiesRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The projection to build on. Null, or left out, is the income
            // and expenses as entered.
            'scenario_id' => ['nullable', 'integer', $this->owned(Scenario::class)],
            // One set for each saved projection instead; `scenario_id` is
            // then not read.
            'every_projection' => ['sometimes', 'boolean'],
        ];
    }
}
