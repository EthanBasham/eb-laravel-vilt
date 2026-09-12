<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Battle Pass tanker, on the way in or on the way to being edited.
 *
 * One class for both: the payload is the same shape either way, and the only
 * difference is that adding someone needs a name up front while editing an
 * existing row may send a single changed field.
 */
class SaveBattlePassCrewRequest extends FormRequest
{
    /**
     * Read '-' as no season.
     *
     * It is how the roster writes a tanker with no season, and the page sends a
     * null for it already. Accepting it here as well means a client that posts
     * the text as typed is not refused for it — a null is what sorts such a
     * tanker to the bottom of the roster, which is where '-' is meant to put
     * them.
     */
    protected function prepareForValidation(): void
    {
        if (trim((string) $this->input('season')) === '-') {
            $this->merge(['season' => null]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A new row has nothing to fall back on, so the name is required on the
        // way in and optional on every edit after it.
        $onCreate = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$onCreate, 'string', 'max:100'],

            // Assumed rather than declared — what the character reads as, not
            // what they can be trained into. Nullable for one there is no guess
            // for.
            'nation' => ['sometimes', 'nullable', Rule::in(array_keys((array) config('wargaming.nations')))],

            // Battle Pass seasons are numbered from 1 and there is no published
            // ceiling; the cap is headroom.
            'season' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],

            'gender' => ['sometimes', 'nullable', Rule::in(array_keys((array) config('wargaming.crew_genders')))],
            'status' => ['sometimes', Rule::in(array_keys((array) config('wargaming.crew_statuses')))],

            // Where they are serving, and as what. Both only mean anything
            // under 'in_tank'; the controller clears them when the status moves
            // off it.
            'tank_id' => ['sometimes', 'nullable', 'integer', 'exists:wot_vehicles,tank_id'],
            'crew_role' => ['sometimes', 'nullable', Rule::in(array_keys((array) config('wargaming.crew_roles')))],
        ];
    }
}
