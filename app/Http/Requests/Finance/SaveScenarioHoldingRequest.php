<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Validator;

/**
 * What a scenario changes about one holding, written whole: its rate, its
 * monthly contribution or payment, and every year-end it pins.
 */
class SaveScenarioHoldingRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Null on either hands the holding back its own.
            'annual_rate' => ['nullable', 'numeric', 'between:-100,100'],
            'monthly_contribution' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            // `present`, not `required`: no pinned years is a real answer.
            'overrides' => ['present', 'array', 'max:150'],
            'overrides.*' => ['required', 'numeric', 'min:0', 'max:999999999999'],
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
                $this->validatePinnedYears($validator, 'a value');
            },
        ];
    }
}
