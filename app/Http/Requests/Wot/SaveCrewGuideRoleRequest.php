<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One role's part of a crew guide: the perks to train, in order, or the note.
 *
 * Either may arrive without the other — a perk is saved as it is dropped and
 * the note as its field is left — so both are `sometimes`.
 */
class SaveCrewGuideRoleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Which perks may be listed depends on the role in the path: a loader
        // cannot be told to train Sixth Sense.
        $trainable = (array) config('wargaming.crew_role_perks.'.$this->route('role'), []);

        return [
            // `present` rather than `required`, which refuses an empty array —
            // and dragging the last perk out leaves exactly that.
            'included' => ['sometimes', 'present', 'array'],
            // The order is the point, so a perk listed twice has no meaning.
            'included.*' => ['string', 'distinct', Rule::in($trainable)],

            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
