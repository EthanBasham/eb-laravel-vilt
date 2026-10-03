<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One rate for every income, or for every expense, in a scenario.
 */
class SaveScenarioRatesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', Rule::in(['income', 'expense'])],
            'annual_growth_rate' => ['required', 'numeric', 'between:-50,50'],
        ];
    }
}
