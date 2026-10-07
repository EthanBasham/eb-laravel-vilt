<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class SavePositionRequest extends FinanceRequest
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
            'symbol' => ['nullable', 'string', 'max:12'],
            'asset_class' => ['required', 'string', Rule::in(array_keys(config('finance.position_classes')))],
            'value' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'expected_return' => ['required', 'numeric', 'between:-100,100'],
            'expense_ratio' => ['required', 'numeric', 'between:0,10'],
        ];
    }
}
