<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An income stream or an expense, created or edited.
 */
class SaveFlowRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $direction = $this->input('direction');

        return [
            'direction' => ['required', 'string', Rule::in(['income', 'expense'])],
            // The category list depends on the direction, so a salary cannot
            // be filed as an expense by posting the two fields mismatched.
            'category' => ['required', 'string', Rule::in(array_keys(config("finance.flow_categories.{$direction}") ?? []))],
            'name' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'frequency' => ['required', 'string', Rule::in(array_keys(config('finance.frequencies')))],
            'hours_per_week' => ['nullable', 'required_if:frequency,hourly', 'numeric', 'between:0,168'],
            'annual_growth_rate' => ['required', 'numeric', 'between:-50,50'],
            // Null is an income that is not taxed. An expense has no treatment.
            'taxation' => ['nullable', 'prohibited_if:direction,expense', 'string', Rule::in(array_keys(config('finance.flow_taxations')))],
            // The share of the income that is taxed at all. Only read when
            // it has a treatment; an expense carries the default and ignores it.
            'taxed_portion' => ['required', 'numeric', 'between:0,100'],
            'is_essential' => ['required', 'boolean'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'holding_id' => ['nullable', 'integer', Rule::exists('fin_holdings', 'id')->where('user_id', $this->user()->id)],
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
            'hours_per_week.required_if' => 'An hourly rate needs the hours a week it is worked.',
        ];
    }
}
