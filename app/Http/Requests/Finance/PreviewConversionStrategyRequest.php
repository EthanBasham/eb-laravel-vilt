<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Scenario;

/**
 * A conversion strategy's settings, to be run on one projection without
 * being kept.
 */
class PreviewConversionStrategyRequest extends SaveConversionStrategyRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // The projection to run them on. Null, or left out, is the
            // income and expenses as entered.
            'scenario_id' => ['nullable', 'integer', $this->owned(Scenario::class)],
        ];
    }
}
