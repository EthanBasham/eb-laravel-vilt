<?php

namespace App\Services\Finance;

/**
 * The simple tools: the calculators that need no fleet, only a form.
 *
 * Each method takes the raw query input and returns `inputs` (as clamped and
 * defaulted — what the form should show) alongside the answer.
 */
class Calculators
{
    /** Slug => title, in the order the tabs print. */
    public const TOOLS = [
        'mortgage' => 'Mortgage',
        'compound' => 'Compound interest',
        'payoff' => 'Debt payoff',
        'savings' => 'Savings target',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function mortgage(array $input): array
    {
        $in = ToolInput::numbers($input, [
            'price' => [400000, 0, 50_000_000],
            'down_pct' => [20, 0, 100],
            'rate' => [6.5, 0, 25],
            'term_years' => [30, 1, 40],
            'tax_annual' => [4800, 0, 1_000_000],
            'insurance_annual' => [1800, 0, 1_000_000],
            'hoa_monthly' => [0, 0, 50_000],
            'extra_monthly' => [0, 0, 1_000_000],
        ]);

        $loan = $in['price'] * (1 - $in['down_pct'] / 100);
        $months = (int) $in['term_years'] * 12;
        $payment = Amortization::payment($loan, $in['rate'], $months);
        // The usual rule of thumb: about half a percent of the loan a year
        // until there is 20% equity. Shown as an estimate, never as a quote.
        $pmi = $in['down_pct'] < 20 ? $loan * 0.005 / 12 : 0.0;

        $standard = Amortization::schedule($loan, $in['rate'], $payment);
        $accelerated = Amortization::schedule($loan, $in['rate'], $payment + $in['extra_monthly']);

        return [
            'inputs' => $in,
            'loan' => round($loan),
            'payment' => round($payment, 2),
            'pmi' => round($pmi, 2),
            'escrow' => round(($in['tax_annual'] + $in['insurance_annual']) / 12 + $in['hoa_monthly'], 2),
            'monthly_total' => round($payment + $pmi + ($in['tax_annual'] + $in['insurance_annual']) / 12 + $in['hoa_monthly'], 2),
            'total_interest' => $standard['total_interest'],
            'months' => $standard['months'],
            'accelerated' => [
                'months' => $accelerated['months'],
                'total_interest' => $accelerated['total_interest'],
                'months_saved' => $standard['months'] - $accelerated['months'],
                'interest_saved' => round($standard['total_interest'] - $accelerated['total_interest'], 2),
            ],
            'years' => $accelerated['years'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function compound(array $input): array
    {
        $in = ToolInput::numbers($input, [
            'principal' => [10000, 0, 100_000_000],
            'monthly' => [500, 0, 1_000_000],
            'rate' => [7, -20, 30],
            'years' => [30, 1, 60],
            'inflation' => [0, 0, 15],
        ]);

        $years = [];

        for ($year = 0; $year <= (int) $in['years']; $year++) {
            $deflator = (1 + $in['inflation'] / 100) ** $year;
            $balance = Amortization::futureValue($in['principal'], $in['monthly'], $in['rate'], $year * 12);
            $contributed = $in['principal'] + $in['monthly'] * 12 * $year;

            $years[] = [
                'year' => $year,
                'balance' => round($balance / $deflator),
                'contributed' => round($contributed),
            ];
        }

        $last = $years[array_key_last($years)];

        return [
            'inputs' => $in,
            'balance' => $last['balance'],
            'contributed' => $last['contributed'],
            'growth' => $last['balance'] - $last['contributed'],
            'years' => $years,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function payoff(array $input): array
    {
        $in = ToolInput::numbers($input, [
            'balance' => [15000, 0, 50_000_000],
            'rate' => [22, 0, 60],
            'payment' => [400, 0, 1_000_000],
            'extra_monthly' => [150, 0, 1_000_000],
        ]);

        $standard = Amortization::schedule($in['balance'], $in['rate'], $in['payment']);
        $accelerated = Amortization::schedule($in['balance'], $in['rate'], $in['payment'] + $in['extra_monthly']);

        $summary = fn (array $schedule): array => [
            'months' => $schedule['months'],
            'paid_off' => $schedule['paid_off'],
            'total_interest' => $schedule['total_interest'],
            'years' => $schedule['years'],
        ];

        return [
            'inputs' => $in,
            'monthly_interest' => round($in['balance'] * $in['rate'] / 100 / 12, 2),
            'standard' => $summary($standard),
            'accelerated' => $summary($accelerated),
            'months_saved' => $standard['paid_off'] && $accelerated['paid_off'] ? $standard['months'] - $accelerated['months'] : null,
            'interest_saved' => $standard['paid_off'] && $accelerated['paid_off'] ? round($standard['total_interest'] - $accelerated['total_interest'], 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function savings(array $input): array
    {
        $in = ToolInput::numbers($input, [
            'target' => [50000, 0, 100_000_000],
            'present' => [5000, 0, 100_000_000],
            'rate' => [4, 0, 30],
            'years' => [5, 1, 60],
        ]);

        $months = (int) $in['years'] * 12;
        $monthly = Amortization::depositFor($in['target'], $in['present'], $in['rate'], $months);
        $years = [];

        for ($year = 0; $year <= (int) $in['years']; $year++) {
            $years[] = [
                'year' => $year,
                'balance' => round(Amortization::futureValue($in['present'], $monthly, $in['rate'], $year * 12)),
                'contributed' => round($in['present'] + $monthly * 12 * $year),
            ];
        }

        return [
            'inputs' => $in,
            'monthly' => round($monthly, 2),
            'contributed' => round($in['present'] + $monthly * $months),
            'growth' => round(max($in['target'], $in['present']) - $in['present'] - $monthly * $months),
            'years' => $years,
        ];
    }
}
