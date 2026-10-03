<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Flow;
use App\Models\Finance\Goal;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\Snapshot;
use App\Models\User;

/**
 * The /finance landing page: the whole fleet on one screen.
 */
class OverviewBoard
{
    /** How far the net-worth projection on the overview runs. */
    private const PROJECTION_YEARS = 30;

    public function __construct(
        private Fleet $fleet,
        private FleetProjector $projector,
        private TaxCalculator $tax,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $holdings = $this->fleet->holdings($user);
        $flows = $this->fleet->flows($user);
        $profile = Profile::for($user);

        $totals = $this->fleet->totals($holdings);
        $cashflow = $this->fleet->cashflow($flows, $this->tax->monthlyRunRateTax($flows, $profile));
        $projection = $this->projector->project($this->fleet->leaves($holdings), self::PROJECTION_YEARS * 12);
        $assets = $this->projector->yearly($projection['assets']);
        $liabilities = $this->projector->yearly($projection['liabilities']);

        $lastSnapshot = Snapshot::query()->onlyOwnedBy($user)->latest('taken_on')->first();

        return [
            'is_empty' => $holdings->isEmpty() && $flows->isEmpty(),
            'totals' => $totals,
            'cashflow' => $cashflow,
            'allocation' => $this->grouped($holdings->where('side', 'asset'), 'group'),
            'debts' => $this->grouped($holdings->where('side', 'liability'), 'type_label'),
            'projection' => collect($assets)->map(fn (float $asset, int $year): array => [
                'year' => now()->year + $year,
                'assets' => $asset,
                'liabilities' => $liabilities[$year],
                'net_worth' => round($asset - $liabilities[$year], 2),
            ])->all(),
            'top_holdings' => $holdings->sortByDesc->value->take(6)->map->props->values()->all(),
            'goals' => Goal::query()->onlyOwnedBy($user)->inDefaultOrder()->limit(4)->get()->map(fn (Goal $goal): array => [
                'id' => $goal->id,
                'name' => $goal->name,
                'target_amount' => $goal->target_amount,
                'saved_amount' => $goal->saved_amount,
                'target_date' => $goal->target_date->toDateString(),
                'progress' => $goal->target_amount > 0 ? round(min(100, $goal->saved_amount / $goal->target_amount * 100), 1) : 0,
            ])->all(),
            'last_snapshot' => $lastSnapshot ? [
                'taken_on' => $lastSnapshot->taken_on->toDateString(),
                'net_worth' => $lastSnapshot->net_worth,
                'change' => round($totals['net_worth'] - $lastSnapshot->net_worth, 2),
            ] : null,
            'insights' => $this->insights($holdings, $flows, $profile, $totals, $cashflow),
        ];
    }

    /**
     * Holdings summed under one of their labels, largest first.
     *
     * @param  Collection<int, Holding>  $holdings
     * @return list<array{label: string, value: float}>
     */
    private function grouped(Collection $holdings, string $by): array
    {
        return $holdings->groupBy($by)
            ->map(fn (Collection $group, string $label): array => ['label' => $label, 'value' => round((float) $group->sum->value, 2)])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    /**
     * The short list of things worth saying about this fleet. Each is a fact
     * with a number in it; none of them is advice.
     *
     * @param  Collection<int, Holding>  $holdings
     * @param  Collection<int, Flow>  $flows
     * @param  array{assets: float, liabilities: float, net_worth: float}  $totals
     * @param  array{income: float, expenses: float, net: float, savings_rate: float}  $cashflow
     * @return list<array{tone: string, title: string, body: string, href?: string}>
     */
    private function insights(Collection $holdings, Collection $flows, Profile $profile, array $totals, array $cashflow): array
    {
        $insights = [];

        $liquid = (float) $holdings->whereIn('type', ['cash', 'savings'])->sum->value;

        if ($cashflow['expenses'] > 0) {
            $months = $liquid / $cashflow['expenses'];

            $insights[] = [
                'tone' => $months >= 6 ? 'good' : ($months >= 3 ? 'info' : 'warn'),
                'title' => number_format($months, 1).' months of runway',
                'body' => 'Cash and savings of $'.number_format($liquid).' against $'.number_format($cashflow['expenses']).' of monthly spending.',
            ];
        }

        if ($cashflow['income'] > 0) {
            $insights[] = [
                'tone' => $cashflow['savings_rate'] >= 20 ? 'good' : ($cashflow['savings_rate'] >= 10 ? 'info' : 'warn'),
                'title' => $cashflow['savings_rate'].'% savings rate',
                'body' => '$'.number_format($cashflow['net']).' a month left after every planned expense.',
                'href' => '/finance/budget',
            ];
        }

        $costly = $holdings->where('side', 'liability')->filter(fn (Holding $holding): bool => $holding->annual_rate >= 10 && $holding->value > 0);

        if ($costly->isNotEmpty()) {
            $interest = $costly->sum(fn (Holding $holding): float => $holding->value * $holding->annual_rate / 100 / 12);

            $insights[] = [
                'tone' => 'warn',
                'title' => '$'.number_format($interest).' a month in high-rate interest',
                'body' => $costly->pluck('name')->join(', ', ' and ').' — at 10% APR or more.',
                'href' => '/finance/calculators/payoff',
            ];
        }

        $traditional = (float) $holdings->where('tax_treatment', 'deferred')->sum->value;

        if ($traditional > 0) {
            $insights[] = [
                'tone' => 'info',
                'title' => 'RMDs begin at '.$profile->rmd_start_age,
                'body' => '$'.number_format($traditional).' sits in tax-deferred accounts. See whether converting some to Roth first comes out ahead.',
                'href' => '/finance/retirement',
            ];
        }

        if ($totals['assets'] > 0 && $totals['liabilities'] > 0) {
            $ratio = $totals['liabilities'] / $totals['assets'] * 100;

            $insights[] = [
                'tone' => $ratio < 40 ? 'good' : 'info',
                'title' => number_format($ratio).'% debt to assets',
                'body' => '$'.number_format($totals['liabilities']).' owed against $'.number_format($totals['assets']).' owned.',
            ];
        }

        if ($profile->birth_date === null) {
            $insights[] = [
                'tone' => 'info',
                'title' => 'Add your birth date',
                'body' => 'The retirement tools are assuming you are 40 until you say otherwise.',
                'href' => '/finance/settings',
            ];
        }

        return $insights;
    }
}
