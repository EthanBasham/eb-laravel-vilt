<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\WotGrind;

class StoreGrindRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Must be a vehicle we actually know about, or the grind would
            // render as a bare id and no target could be resolved for it.
            'tank_id' => ['required', 'integer', Rule::exists('wot_vehicles', 'tank_id')],
            'target_type' => ['required', Rule::in([WotGrind::TARGET_TANK, WotGrind::TARGET_MODULE])],
            'target_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
