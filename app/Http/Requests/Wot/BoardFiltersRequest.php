<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Where the Tanks to Purchase filter row was left.
 *
 * Every key is `sometimes`, so a client that learns a fifth filter can send
 * only that one, and a partial payload never clears the rest.
 */
class UpdatePurchaseFiltersRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * The filters are held as what is *hidden*, so an empty array is
             * the common case — "nothing hidden" — rather than a missing value.
             * present, not required, for that reason.
             */
            'hidden_nations' => ['sometimes', 'present', 'array', 'max:50'],
            'hidden_nations.*' => [Rule::in(array_keys((array) config('wargaming.nations')))],
            'hidden_tiers' => ['sometimes', 'present', 'array', 'max:20'],
            // 11 is the tech tree's current ceiling, matching PurchaseBoard.
            'hidden_tiers.*' => ['integer', 'min:1', 'max:11'],
            'hide_owned' => ['sometimes', 'boolean'],
            'show_sale' => ['sometimes', 'boolean'],
        ];
    }
}
