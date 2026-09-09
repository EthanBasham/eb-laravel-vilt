<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a vehicle costs to unlock once blueprint fragments are counted.
 *
 * Typed by hand because Wargaming publishes no fragments-to-discount curve. The
 * ceiling is the dearest unlock in the tree with room to spare, not a game
 * rule.
 */
class UpdateResearchXpRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nullable so clearing the field restores the encyclopedia figure
            // rather than recording that the tank unlocks for nothing.
            'research_xp' => ['present', 'nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }
}
