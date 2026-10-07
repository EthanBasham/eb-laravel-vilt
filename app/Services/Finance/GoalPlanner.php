<?php

namespace App\Services\Finance;

use App\Models\Finance\Goal;
use App\Models\User;

/**
 * Target-Based Savings Plans: for each goal, the different ways of getting
 * there by the date, and what each costs a month.
 */
class GoalPlanner
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function for(User $user, array $input): array
    {
        $options = ToolInput::numbers($input, [
            'savings_rate' => [config('finance.defaults.savings_rate'), 0, 15],
            'market_return' => [config('finance.defaults.market_return'), 0, 20],
            'loan_rate' => [8, 0, 40],
            'loan_years' => [5, 1, 30],
        ]);

        return [
            'options' => $options,
            'goals' => Goal::query()->onlyOwnedBy($user)->inDefaultOrder()->get()
                ->map(fn (Goal $goal): array => $this->plan($goal, $options))
                ->all(),
        ];
    }

    /**
     * @param  array<string, float>  $options
     * @return array<string, mixed>
     */
    public function plan(Goal $goal, array $options): array
    {
        $months = $goal->months_remaining;
        $spread = (float) config('finance.defaults.scenario_spread');

        $saving = fn (string $key, string $label, float $rate, string $note): array => $this->savingStrategy($goal, $key, $label, $rate, $note);

        $invest = $saving('invest', 'Invest it', $options['market_return'], 'Cheapest if markets cooperate, but the balance can be down when the date arrives.');
        // What the same deposits come to if returns run the scenario spread
        // below expectation — the honest caveat on the cheapest plan.
        $cautious = Amortization::futureValue($goal->saved_amount, $invest['monthly'], $options['market_return'] - 2 * $spread, $months);
        $invest['downside_shortfall'] = round(max(0.0, $goal->target_amount - $cautious));
        $invest['downside_points'] = 2 * $spread;

        $loanMonths = (int) $options['loan_years'] * 12;
        $borrowed = $goal->remaining_amount;
        $loanPayment = Amortization::payment($borrowed, $options['loan_rate'], $loanMonths);
        $loanInterest = $loanPayment * $loanMonths - $borrowed;

        return [
            'id' => $goal->id,
            'name' => $goal->name,
            'target_amount' => $goal->target_amount,
            'saved_amount' => $goal->saved_amount,
            'target_date' => $goal->target_date->toDateString(),
            'months_remaining' => $months,
            'progress' => $goal->target_amount > 0 ? round(min(100, $goal->saved_amount / $goal->target_amount * 100), 1) : 0,
            'strategies' => [
                $saving('cash', 'Plain savings', 0, 'No growth, no risk. The baseline every other plan is measured against.'),
                $saving('hysa', 'High-yield savings', $options['savings_rate'], 'Interest does a little of the work and the balance never falls.'),
                $invest,
                [
                    'key' => 'finance',
                    'label' => 'Buy now, finance it',
                    'rate' => $options['loan_rate'],
                    'monthly' => round($loanPayment, 2),
                    'months' => $loanMonths,
                    'total_paid' => round($goal->saved_amount + $loanPayment * $loanMonths, 2),
                    'growth' => round(-$loanInterest, 2),
                    'note' => 'You have it today, and pay interest for the privilege instead of earning it.',
                    'series' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function savingStrategy(Goal $goal, string $key, string $label, float $rate, string $note): array
    {
        $months = $goal->months_remaining;
        $monthly = Amortization::depositFor($goal->target_amount, $goal->saved_amount, $rate, $months);
        $deposited = $goal->saved_amount + $monthly * $months;
        $series = [];

        // A point per month up to two years out, then quarterly, so a
        // twenty-year goal does not ship 240 points for one small chart.
        $step = $months > 24 ? 3 : 1;

        for ($month = 0; $month <= $months; $month += $step) {
            $series[] = ['month' => $month, 'value' => round(Amortization::futureValue($goal->saved_amount, $monthly, $rate, $month))];
        }

        if ($months % $step !== 0) {
            $series[] = ['month' => $months, 'value' => round(Amortization::futureValue($goal->saved_amount, $monthly, $rate, $months))];
        }

        return [
            'key' => $key,
            'label' => $label,
            'rate' => $rate,
            'monthly' => round($monthly, 2),
            'months' => $months,
            'total_paid' => round($deposited, 2),
            // What the balance actually reaches, which is past the target
            // when what is saved already outgrows it with nothing added.
            'growth' => round(Amortization::futureValue($goal->saved_amount, $monthly, $rate, $months) - $deposited, 2),
            'note' => $note,
            'series' => $series,
        ];
    }
}
