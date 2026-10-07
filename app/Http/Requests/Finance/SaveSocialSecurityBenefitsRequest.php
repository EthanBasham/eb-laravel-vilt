<?php

namespace App\Http\Requests\Finance;

/**
 * What the Social Security tool needs to know about the household: each
 * person's monthly benefit at full retirement age, and the spouse's dates.
 * Saved on the profile, since they are facts about the people, not about any
 * one strategy.
 */
class SaveSocialSecurityBenefitsRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ss_monthly_benefit' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            // Null is nobody to claim for but the profile's owner.
            'spouse_birth_date' => ['nullable', 'date', 'before:today'],
            'spouse_ss_monthly_benefit' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'spouse_life_expectancy' => ['nullable', 'integer', 'between:50,120'],
        ];
    }
}
