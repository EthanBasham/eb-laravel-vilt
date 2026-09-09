<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ownership state for one vehicle. Nothing here comes from the API: the public
 * endpoints report what you have played, never what you have researched or what
 * a seasonal discount reduced the price to.
 */
class UpdateTankPurchaseRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_unlocked' => ['sometimes', 'boolean'],
            'is_purchased' => ['sometimes', 'boolean'],
            // Nullable so clearing the field restores the encyclopedia price
            // rather than recording that the tank is free.
            'price_credit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
