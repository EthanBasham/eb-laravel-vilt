<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Scenario;

/**
 * One strategy, put in the Roth report on the projections named.
 */
class AddToReportRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'strategy_id' => ['required', 'integer', $this->owned(ConversionStrategy::class)],
            // Null is the income and expenses as entered.
            'projections' => ['required', 'array', 'min:1'],
            'projections.*' => ['nullable', 'integer', 'distinct', $this->owned(Scenario::class)],
        ];
    }
}
