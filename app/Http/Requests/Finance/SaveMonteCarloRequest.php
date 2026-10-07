<?php

namespace App\Http\Requests\Finance;

class SaveMonteCarloRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 0 turns Monte Carlo off.
            'runs' => ['required', 'integer', 'between:0,'.config('finance.monte_carlo.max_runs')],
            'return_volatility' => ['required', 'numeric', 'between:0,50'],
            'inflation_volatility' => ['required', 'numeric', 'between:0,10'],
        ];
    }
}
