<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMilestoneRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // jQuery serialises the checkbox as "1"/"0", so this has to accept
            // the string forms `boolean` allows, not a literal PHP bool.
            'is_complete' => ['required', 'boolean'],
        ];
    }
}
