<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * How many blueprints of one nation are held, or universal ones.
 *
 * Which stack is being written is carried by the route, so the payload is the
 * bare number — the same shape as a count on the Crews page's books table.
 */
class UpdateBlueprintStockRequest extends FormRequest
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
            // count here.
            'quantity' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
