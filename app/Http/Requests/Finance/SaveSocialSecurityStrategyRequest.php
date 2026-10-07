<?php

namespace App\Http\Requests\Finance;

/**
 * A Social Security claiming strategy, created or edited.
 */
class SaveSocialSecurityStrategyRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $earliest = (int) config('finance.social_security.earliest_age');
        $latest = (int) config('finance.social_security.latest_age');

        return [
            'name' => ['required', 'string', 'max:80'],
            'claim_age' => ['required', 'integer', "between:{$earliest},{$latest}"],
            'claim_months' => ['required', 'integer', 'between:0,11'],
            // Null claims at the same age as the profile's owner.
            'spouse_claim_age' => ['nullable', 'integer', "between:{$earliest},{$latest}"],
            'spouse_claim_months' => ['required', 'integer', 'between:0,11'],
            'cola_rate' => ['nullable', 'numeric', 'between:-5,15'],
            'discount_rate' => ['nullable', 'numeric', 'between:-10,20'],
        ];
    }
}
