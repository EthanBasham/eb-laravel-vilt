<?php

namespace App\Services\Finance;

use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\User;

/**
 * The Portfolio Projector: the investable part of the fleet, grown forward
 * under a cautious, an expected and an optimistic return.
 */
class ProjectorBoard
{
    /** Round numbers worth knowing the arrival year of. */
    private const MILESTONES = [100_000, 250_000, 500_000, 1_000_000, 2_000_000, 5_000_000, 10_000_000];

    public function __construct(
        private Fleet $fleet,
        private FleetProjector $projector,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function for(User $user, array $input): array
    {
        $profile = Profile::for($user);

        $options = ToolInput::numbers($input, [
            'years' => [30, 1, 60],
            'spread' => [config('finance.defaults.scenario_spread'), 0, 10],
            'extra_monthly' => [0, 0, 1_000_000],
            'real' => [0, 0, 1],
        ]);

        $holdings = $this->fleet->leaves($this->fleet->holdings($user))->filter->is_investable->values();
        $months = (int) $options['years'] * 12;
        $inflation = 1 + $profile->inflation_rate / 100;

        // Today's dollars when asked: each year-end divided by the price
        // level it will be spent at.
        $shown = fn (array $monthly): array => collect($this->projector->yearly($monthly))
            ->map(fn (float $value, int $year): float => $options['real'] ? round($value / $inflation ** $year) : round($value))
            ->all();

        $expected = $this->projector->project($holdings, $months, 0.0, $options['extra_monthly']);
        $cautious = $shown($this->projector->project($holdings, $months, -$options['spread'], $options['extra_monthly'])['assets']);
        $optimistic = $shown($this->projector->project($holdings, $months, $options['spread'], $options['extra_monthly'])['assets']);
        $middle = $shown($expected['assets']);

        $start = $middle[0] ?? 0.0;
        $end = $middle[array_key_last($middle)] ?? 0.0;
        $thisYear = now()->year;

        return [
            'options' => $options,
            'inflation_rate' => $profile->inflation_rate,
            'has_holdings' => $holdings->isNotEmpty(),
            'series' => collect($middle)->map(fn (float $value, int $year): array => [
                'year' => $thisYear + $year,
                'cautious' => $cautious[$year],
                'expected' => $value,
                'optimistic' => $optimistic[$year],
            ])->all(),
            'summary' => [
                'start' => $start,
                'end' => $end,
                'cautious_end' => $cautious[array_key_last($cautious)] ?? 0.0,
                'optimistic_end' => $optimistic[array_key_last($optimistic)] ?? 0.0,
                'contributed' => round($expected['contributed']),
                'growth' => round($end - $start - $expected['contributed']),
                'monthly_contribution' => round((float) $holdings->sum('monthly_contribution') + ($holdings->isNotEmpty() ? $options['extra_monthly'] : 0), 2),
            ],
            'holdings' => $holdings->map(function (Holding $holding) use ($expected, $shown): array {
                $values = $shown($expected['holdings'][$holding->id]);

                return [
                    'id' => $holding->id,
                    'name' => $holding->full_name,
                    'parent_id' => $holding->parent_id,
                    'type_label' => $holding->type_label,
                    'rate' => round($holding->expected_rate, 2),
                    'monthly_contribution' => $holding->monthly_contribution,
                    'start' => $values[0],
                    'end' => $values[array_key_last($values)],
                ];
            })->all(),
            'milestones' => collect(self::MILESTONES)
                ->filter(fn (int $amount): bool => $amount > $start)
                ->map(function (int $amount) use ($middle, $thisYear): ?array {
                    $year = collect($middle)->search(fn (float $value): bool => $value >= $amount);

                    return $year === false ? null : ['amount' => $amount, 'year' => $thisYear + $year, 'years_away' => $year];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }
}
