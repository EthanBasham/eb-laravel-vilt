<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One module, researched or not, on a vehicle.
 *
 * The module is checked against the encyclopedia in the model rather than here:
 * the rule is "this id is an upgrade module on *this* vehicle", which needs the
 * tank the route is carrying.
 */
class UpdateModuleResearchRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'module_id' => ['required', 'integer'],
            'researched' => ['required', 'boolean'],
        ];
    }
}
