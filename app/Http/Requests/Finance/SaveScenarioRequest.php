<?php

namespace App\Http\Requests\Finance;

class SaveScenarioRequest extends FinanceRequest
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
            'description' => ['nullable', 'string', 'max:200'],
            // How fast the tax tables rise. Null follows the profile's
            // inflation rate; left out, as the rename dialog does, it is kept.
            'bracket_inflation_rate' => ['nullable', 'numeric', 'between:-5,15'],
        ];
    }
}
