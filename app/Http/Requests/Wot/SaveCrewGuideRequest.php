<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A crew guide's name, on the way in or on a rename.
 *
 * The name is all a guide has of its own; what each role trains is saved a
 * role at a time, through SaveCrewGuideRoleRequest.
 */
class SaveCrewGuideRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
