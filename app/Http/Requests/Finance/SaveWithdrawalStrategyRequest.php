<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;
use App\Models\Finance\Scenario;

/**
 * A withdrawal strategy, created or edited. The blanks fall back as a
 * conversion strategy's do: the projection's inflation, the fleet's growth.
 */
class SaveWithdrawalStrategyRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'string', Rule::in(array_keys(config('finance.withdrawal_strategies')))],
            // Only one of the user's own projections can be built on.
            'scenario_id' => ['nullable', 'integer', $this->owned(Scenario::class)],
            // Null fills whichever bracket the year's income is already in.
            'fill_rate' => ['nullable', 'numeric', Rule::in(config('finance.conversion_fill_rates'))],
            'spending_rule' => ['required', 'string', Rule::in(array_keys(config('finance.spending_rules')))],
            // A year's withdrawal in today's dollars, or as a share of the
            // balance, by the rule. The projection rule reads neither.
            'spending_amount' => ['nullable', 'required_if:spending_rule,fixed', 'numeric', 'min:0', 'max:999999999'],
            'spending_percent' => ['nullable', 'required_if:spending_rule,percent', 'numeric', 'between:0,100'],
            'inflation_rate' => ['nullable', 'numeric', 'between:-5,15'],
            'growth_rate' => ['nullable', 'numeric', 'between:-10,20'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'spending_amount.required_if' => 'Say how much to take each year.',
            'spending_percent.required_if' => 'Say what share of the balance to take each year.',
        ];
    }
}
