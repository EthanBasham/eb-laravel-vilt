<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * What a scenario changes about one flow, written whole: its rate, every
 * year it pins, and which of those the rate starts again from.
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
            // The pinned years the rate starts again from. Left out is none.
            'restarts' => ['sometimes', 'array', 'max:150'],
            'restarts.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * The keys of `overrides` are the years being pinned, which a per-field
     * rule cannot see; and a year the rate starts again from has to be one
     * of them.
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

                $restarts = $this->input('restarts');

                if (is_array($restarts) && array_diff(array_map(strval(...), $restarts), array_map(strval(...), array_keys($overrides))) !== []) {
                    $validator->errors()->add('restarts', 'The rate can only start again from a year that has been set by hand.');
                }
            },
        ];
    }
}
