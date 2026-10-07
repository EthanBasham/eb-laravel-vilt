<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;
use App\Models\Finance\Scenario;

/**
 * A conversion strategy, created or edited. Most of it may be left blank:
 * see the migration for what each blank falls back to.
 */
class SaveConversionStrategyRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Blank is shown as its projection and its kind.
            'name' => ['nullable', 'string', 'max:80'],
            'kind' => ['required', 'string', Rule::in(array_keys(config('finance.conversion_strategies')))],
            // Only one of the user's own projections can be built on.
            'scenario_id' => ['nullable', 'integer', $this->owned(Scenario::class)],
            'convert_from_age' => ['nullable', 'integer', 'between:18,110'],
            'convert_until_age' => ['nullable', 'integer', 'between:18,110', 'gte:convert_from_age'],
            // Null fills whichever bracket the year's income is already in.
            'fill_rate' => ['nullable', 'numeric', Rule::in(config('finance.conversion_fill_rates'))],
            // A year's conversion for the fixed-amount kind, in today's dollars.
            'conversion_amount' => ['nullable', 'required_if:kind,fixed', 'numeric', 'min:1', 'max:999999999'],
            'tax_payment' => ['required', 'string', Rule::in(array_keys(config('finance.conversion_tax_payments')))],
            // How much of the conversion's tax comes from outside it: a
            // percentage, or dollars a year, by the mode. The other two modes
            // do not read it.
            'tax_outside_amount' => ['nullable', 'required_if:tax_payment,percent,flat', 'numeric', 'min:0', $this->input('tax_payment') === 'percent' ? 'max:100' : 'max:999999999'],
            'inflation_rate' => ['nullable', 'numeric', 'between:-5,15'],
            'growth_rate' => ['nullable', 'numeric', 'between:-10,20'],
            'heir_is_charity' => ['required', 'boolean'],
            'heir_income' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
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
            'convert_until_age.gte' => 'The last age to convert at cannot be before the first.',
            'conversion_amount.required_if' => 'Say how much to convert each year.',
            'tax_outside_amount.required_if' => 'Say how much of the tax is paid from outside the conversion.',
            'tax_outside_amount.max' => 'No more than all of it can be paid from outside the conversion.',
        ];
    }
}
