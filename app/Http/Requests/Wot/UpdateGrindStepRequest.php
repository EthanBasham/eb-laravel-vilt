<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The manual half of the board. Every field here is something the Wargaming API
 * cannot report, which is the whole reason this page exists.
 */
class UpdateGrindStepRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'banked_xp' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            // Nullable on purpose: clearing it falls back to the API's full
            // price, which is the right behaviour for a step whose blueprint
            // discount hasn't been worked out yet.
            'research_xp_remaining' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'module_xp_remaining' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
