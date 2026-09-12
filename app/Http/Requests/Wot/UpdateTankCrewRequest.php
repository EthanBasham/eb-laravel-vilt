<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A whole crew, as the editor sends it.
 *
 * The modal edits every seat at once and saves in one go, so this is the
 * complete set rather than a patch of one field: a member missing from the
 * payload is a member who has left the vehicle, and the controller deletes it.
 *
 * Emptying a crew is not done here. A vehicle with no crew is the absence of
 * the row, not a row full of zeroes, so clearing one is a DELETE.
 */
class UpdateTankCrewRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Derived rather than written out, so the ceiling on the board and the
        // ceiling the server accepts cannot drift apart.
        $maxSkill = max(array_keys((array) config('wargaming.crew_xp')));

        return [
            'is_balanced' => ['sometimes', 'boolean'],

            // Twelve is headroom. The largest crew the encyclopedia publishes
            // is six, and the controller rejects any slot the vehicle does not
            // have anyway.
            'members' => ['required', 'array', 'max:12'],
            'members.*.slot' => ['required', 'integer', 'min:0', 'max:11'],
            /*
             * How many steps of XP have been zeroed out: 1 or 2 on a zero-skill
             * crew member, 0 on an ordinary one. Zero is accepted because the
             * editor sends every seat, including the ones with nothing special
             * about them.
             */
            'members.*.zero_skills' => ['required', 'integer', 'min:0', 'max:2'],
            // 0 is a real level — trained to 100% with no skills on top.
            'members.*.skill_level' => ['required', 'integer', 'min:0', "max:{$maxSkill}"],
            'members.*.is_max' => ['required', 'boolean'],
            // The same ceiling banked tank XP takes; headroom rather than a
            // game rule.
            'members.*.banked_xp' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
