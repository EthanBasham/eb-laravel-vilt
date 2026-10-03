<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveProfileRequest extends FormRequest
{
    /** Longer than any real schedule; New York's, the longest preset, has nine. */
    private const MAX_BRACKETS = 15;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'filing_status' => ['required', 'string', Rule::in(array_keys(config('finance.tax.filing_statuses')))],
            'retirement_age' => ['required', 'integer', 'between:30,90'],
            'life_expectancy' => ['required', 'integer', 'between:50,120', 'gt:retirement_age'],
            'inflation_rate' => ['required', 'numeric', 'between:0,15'],

            // A preset's key, or "other" for a state typed in by hand. The
            // figures below are taken as given either way: a preset only
            // fills the form in, it is not looked up again on save.
            'state' => ['nullable', 'string', Rule::in([...array_keys(config('finance.tax.states')), 'other'])],
            'state_deduction' => ['required', 'numeric', 'min:0', 'max:999999999'],
            ...$this->bracketRules('state_brackets'),

            'local_name' => ['nullable', 'string', 'max:60'],
            'local_deduction' => ['required', 'numeric', 'min:0', 'max:999999999'],
            ...$this->bracketRules('local_brackets'),

            // Null on the deduction or the capital gains brackets hands them
            // back to the built-in figures for the filing status.
            'standard_deduction' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'se_tax_rate' => ['sometimes', 'required', 'numeric', 'between:0,50'],
            ...$this->bracketRules('ltcg_brackets'),
            'ltcg_brackets' => ['nullable', 'array', 'min:1', 'max:'.self::MAX_BRACKETS],
        ];
    }

    /**
     * What a bracket list has to get right that a per-field rule cannot see:
     * each bracket ends above the one before it, and only the last may be
     * open-ended. TaxCalculator walks the list in order and trusts both.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['state_brackets', 'local_brackets', 'ltcg_brackets'] as $field) {
                    $brackets = $this->input($field);

                    if (! is_array($brackets) || $validator->errors()->has("{$field}.*")) {
                        continue;
                    }

                    $last = array_key_last($brackets);
                    $previous = 0.0;

                    foreach ($brackets as $index => $bracket) {
                        $upTo = $bracket['up_to'] ?? null;

                        if ($upTo === null) {
                            if ($index !== $last) {
                                $validator->errors()->add("{$field}.{$index}.up_to", 'Only the last bracket can be left open-ended.');
                            }

                            continue;
                        }

                        if ((float) $upTo <= $previous) {
                            $validator->errors()->add("{$field}.{$index}.up_to", 'Each bracket has to end above the one before it.');
                        }

                        $previous = (float) $upTo;
                    }
                }
            },
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
            '*.*.rate.required' => 'Each bracket needs a rate.',
            '*.*.rate.between' => 'A rate is a percentage between 0 and 100.',
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function bracketRules(string $field): array
    {
        return [
            // `present`, not `required`: an empty list is a real answer — a
            // state with no income tax — and `required` would refuse it.
            $field => ['present', 'array', 'max:'.self::MAX_BRACKETS],
            "{$field}.*.rate" => ['required', 'numeric', 'between:0,100'],
            "{$field}.*.up_to" => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }
}
