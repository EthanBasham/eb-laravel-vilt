<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What a scenario changes about one flow, written whole: its rate, and every
 * year it pins.
 */
class SaveScenarioFlowRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Null hands the flow back its own rate.
            'annual_growth_rate' => ['nullable', 'numeric', 'between:-50,50'],
            // `present`, not `required`: no pinned years is a real answer.
            'overrides' => ['present', 'array', 'max:150'],
            'overrides.*' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    /**
     * The keys of `overrides` are the years being pinned, which a per-field
     * rule cannot see.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $overrides = $this->input('overrides');

                if (! is_array($overrides)) {
                    return;
                }

                foreach (array_keys($overrides) as $year) {
                    if (! ctype_digit((string) $year) || (int) $year < now()->year || (int) $year > now()->year + 150) {
                        $validator->errors()->add('overrides', 'Only a year from this one on can be given an amount of its own.');

                        return;
                    }
                }
            },
        ];
    }
}
