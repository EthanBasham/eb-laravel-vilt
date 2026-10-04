<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Holdings and flows moved into an armada, or out of every one.
 *
 * The ids are not checked for ownership here: the controller only ever
 * updates rows through the owner's scope, so someone else's id moves nothing.
 */
class AssignArmadaRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Null takes them out of whichever armada they were in.
            'armada_id' => ['present', 'nullable', 'integer', Rule::exists('fin_armadas', 'id')->where('user_id', $this->user()->id)],
            'holdings' => ['array', 'max:500'],
            'holdings.*' => ['integer'],
            'flows' => ['array', 'max:500'],
            'flows.*' => ['integer'],
        ];
    }
}
