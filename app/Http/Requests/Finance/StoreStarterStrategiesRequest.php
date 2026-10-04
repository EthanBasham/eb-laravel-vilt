<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One conversion strategy of each kind, made in one go: for a single
 * projection, or for every projection the user has.
 */
class StoreStarterStrategiesRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The projection to build on. Null, or left out, is the income
            // and expenses as entered.
            'scenario_id' => ['nullable', 'integer', Rule::exists('fin_scenarios', 'id')->where('user_id', $this->user()->id)],
            // One set for each saved projection instead; `scenario_id` is
            // then not read.
            'every_projection' => ['sometimes', 'boolean'],
        ];
    }
}
