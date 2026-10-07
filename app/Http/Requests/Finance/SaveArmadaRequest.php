<?php

namespace App\Http\Requests\Finance;

/**
 * An armada, created or renamed.
 */
class SaveArmadaRequest extends FinanceRequest
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
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
