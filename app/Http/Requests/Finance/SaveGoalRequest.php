<?php

namespace App\Http\Requests\Finance;

class SaveGoalRequest extends FinanceRequest
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
            'target_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'saved_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'target_date' => ['required', 'date'],
        ];
    }
}
