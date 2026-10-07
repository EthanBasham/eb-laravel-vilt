<?php

namespace App\Http\Requests\Finance;

use Illuminate\Support\Carbon;

/**
 * What one flow actually came to in one month. A null amount clears it.
 */
class SaveActualRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'amount' => ['present', 'nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    /** The month being recorded, as the first of it. */
    public function month(): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $this->validated('month'));
    }
}
