<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The whole comparison, replaced: the strategies named are compared and
 * every other one the user has goes to the holding area.
 */
class ReplaceComparisonRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $most = (int) config('finance.conversion_comparison.max');

        return [
            'strategies' => ['required', 'array', 'min:1', "max:{$most}"],
            'strategies.*' => ['integer', 'distinct', Rule::exists('fin_conversion_strategies', 'id')->where('user_id', $this->user()->id)],
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
            'strategies.required' => 'Pick at least one strategy for the report.',
            'strategies.max' => 'No more than :max strategies can be in the report at once.',
        ];
    }
}
