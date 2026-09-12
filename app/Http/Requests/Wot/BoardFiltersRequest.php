<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Where a board's filter row was left.
 *
 * Serves every grid in the app. `board` picks which column the payload lands in; the rest
 * of the keys are `sometimes`, so a client sends only what changed and a
 * partial payload never clears the rest. hide_owned belongs to the purchase
 * board, only_planned and hide_researched to Free XP, hide_done to XP Remaining
 * and only_crewed to Crews, but none is rejected on another — a key a board
 * never sends is simply one it never stores.
 */
class BoardFiltersRequest extends FormRequest
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
            'board' => ['required', Rule::in(['purchase', 'freexp', 'xp', 'blueprints', 'crews'])],
            'hidden_nations' => ['sometimes', 'present', 'array', 'max:50'],
            'hidden_nations.*' => [Rule::in(array_keys((array) config('wargaming.nations')))],
            'hidden_tiers' => ['sometimes', 'present', 'array', 'max:20'],
            // 11 is the tech tree's current ceiling, matching PurchaseBoard.
            'hidden_tiers.*' => ['integer', 'min:1', 'max:11'],
            'hide_owned' => ['sometimes', 'boolean'],
            'show_sale' => ['sometimes', 'boolean'],
            'only_planned' => ['sometimes', 'boolean'],
            'hide_researched' => ['sometimes', 'boolean'],
            'hide_done' => ['sometimes', 'boolean'],
            'only_crewed' => ['sometimes', 'boolean'],
        ];
    }
}
