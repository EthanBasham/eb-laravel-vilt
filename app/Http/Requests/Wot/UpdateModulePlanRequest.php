<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One module, on or off a vehicle's Free XP plan.
 *
 * The module is checked against the encyclopedia in the model rather than here:
 * the rule is "this id is an upgrade module on *this* vehicle", which needs the
 * tank the route is carrying and is the same check whatever writes the plan.
 */
class UpdateModulePlanRequest extends FormRequest
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
            'planned' => ['required', 'boolean'],
        ];
    }
}
