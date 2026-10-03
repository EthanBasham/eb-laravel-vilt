<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What one flow actually came to in one month. A null amount clears it.
 */
class SaveActualRequest extends FormRequest
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
}
