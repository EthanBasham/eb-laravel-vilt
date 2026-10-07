<?php

namespace App\Services\Finance;

use App\Models\Finance\Profile;
use App\Models\User;

/**
 * The settings page: the profile the tools read, and the tax and IRMAA
 * tables in force for it.
 */
class SettingsBoard
{
    public function __construct(
        private SampleFleet $sample,
        private TaxCalculator $tax,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $profile = Profile::for($user);
        $status = $profile->filing_status;

        return [
            'profile' => $profile->props,
            'is_empty' => $this->sample->isEmptyFor($user),
            // What the state and local fields can be filled in from. Only
            // this page needs them, so they are not shared with every visit.
            'presets' => config('finance.tax.states'),
            'tax' => [
                'year' => (int) config('finance.tax.year'),
                // What is in force, then what is built in for the filing
                // status — the same thing until the profile sets its own.
                'deduction' => $this->tax->forProfile($profile)->deduction($status),
                'built_in_deduction' => $this->tax->deduction($status),
                // Added on top from 65, whichever deduction is in force.
                'additional_deduction' => (float) config("finance.tax.additional_deduction.{$status}"),
                'brackets' => $this->tax->brackets($status),
                'capital_gains_brackets' => $this->tax->capitalGainsBrackets($profile),
                // What a W-2 wage pays: the employee's half.
                'fica_rate' => round($profile->se_tax_rate / 2, 3),
                'built_in_capital_gains_brackets' => config("finance.tax.capital_gains_brackets.{$status}"),
            ],
            'irmaa' => [
                'year' => (int) config('finance.irmaa.year'),
                'income_year' => (int) config('finance.irmaa.income_year'),
                'tiers' => config("finance.irmaa.tiers.{$status}"),
            ],
        ];
    }
}
