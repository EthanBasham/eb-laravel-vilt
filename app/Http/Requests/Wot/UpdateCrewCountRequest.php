<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One count on the Recruits & Books tab.
 *
 * Both tables are grids of a single number, and which number is being written
 * is carried by the route rather than by the payload — so one request serves
 * every cell on the tab.
 */
class UpdateCrewCountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Headroom rather than a game rule, like every other hand-entered
            // figure here.
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
