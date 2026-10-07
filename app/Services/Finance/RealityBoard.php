<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;
use App\Models\Finance\Actual;
use App\Models\Finance\Flow;
use App\Models\Finance\Snapshot;
use App\Models\User;

/**
 * Projected vs Reality: where the first snapshot said net worth would be by
 * now, against where each later snapshot found it.
 */
class RealityBoard
{
    /** How many months of projection each snapshot stores. */
    public const PROJECTION_MONTHS = 120;

    /** How many months of budget history the page shows. */
    private const BUDGET_MONTHS = 6;

    public function __construct(
        private Fleet $fleet,
        private FleetProjector $projector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $snapshots = Snapshot::query()->onlyOwnedBy($user)->inDefaultOrder()->get();
        $baseline = $snapshots->first();
        $totals = $this->fleet->totals($this->fleet->holdings($user));

        $expectedAt = function (Carbon $date) use ($baseline): ?float {
            $month = (int) round($baseline->taken_on->diffInDays($date) / 30.4375);

            return $baseline->projection[$month] ?? null;
        };

        $points = $snapshots->map(function (Snapshot $snapshot) use ($expectedAt): array {
            $expected = $expectedAt($snapshot->taken_on);

            return [
                'id' => $snapshot->id,
                'taken_on' => $snapshot->taken_on->toDateString(),
                'assets' => $snapshot->assets,
                'liabilities' => $snapshot->liabilities,
                'net_worth' => $snapshot->net_worth,
                'expected' => $expected,
                'variance' => $expected === null ? null : round($snapshot->net_worth - $expected, 2),
                'note' => $snapshot->note,
            ];
        });

        // The projected line runs a year past the latest snapshot, so there
        // is always somewhere for reality to be heading.
        $horizon = $baseline ? min(self::PROJECTION_MONTHS, (int) round($baseline->taken_on->diffInDays($snapshots->last()->taken_on) / 30.4375) + 12) : 0;

        return [
            'current' => $totals,
            'has_snapshot_today' => $snapshots->contains(fn (Snapshot $snapshot): bool => $snapshot->taken_on->isToday()),
            'snapshots' => $points->reverse()->values()->all(),
            'actual' => $points->map(fn (array $point): array => ['date' => $point['taken_on'], 'value' => $point['net_worth']])->all(),
            'projected' => $baseline ? collect($baseline->projection)->take($horizon + 1)->map(fn (float|int $value, int $month): array => [
                'date' => $baseline->taken_on->copy()->addMonthsNoOverflow($month)->toDateString(),
                'value' => (float) $value,
            ])->values()->all() : [],
            'budget' => $this->budgetHistory($user),
        ];
    }

    /**
     * Records today's totals, replacing any snapshot already taken today.
     */
    public function capture(User $user, ?string $note = null): Snapshot
    {
        $holdings = $this->fleet->holdings($user);
        $totals = $this->fleet->totals($holdings);
        $projection = $this->projector->project($this->fleet->leaves($holdings), self::PROJECTION_MONTHS)['net_worth'];

        $attributes = [...$totals, 'projection' => array_map(fn (float $value): float => round($value), $projection), 'note' => $note];

        // whereDate, not updateOrCreate's attribute match — see
        // Actual::record() for why a date cannot be matched as a string
        // across drivers.
        $snapshot = Snapshot::query()->onlyOwnedBy($user)->whereDate('taken_on', now())->first();

        if ($snapshot) {
            $snapshot->update($attributes);

            return $snapshot;
        }

        return Snapshot::query()->create([...$attributes, 'user_id' => $user->id, 'taken_on' => now()->toDateString()]);
    }

    /**
     * Planned against recorded, for the last few months. Only flows with a
     * figure recorded are compared — a month where three of thirty expenses
     * were logged would otherwise look like a triumph of thrift.
     *
     * @return list<array{month: string, label: string, planned: float, actual: float, tracked: int}>
     */
    private function budgetHistory(User $user): array
    {
        $from = now()->startOfMonth()->subMonths(self::BUDGET_MONTHS - 1);

        // Through the leaves, as the budget itself counts: a figure recorded
        // on a flow that has since been broken into items is left out, or it
        // would be counted beside the items' own.
        $leaves = $this->fleet->flowLeaves($this->fleet->flows($user))->keyBy('id');

        $actuals = Actual::query()->onlyOwnedBy($user)->whereDate('month', '>=', $from)->whereIn('flow_id', $leaves->keys())->get()
            ->groupBy(fn (Actual $actual): string => $actual->month->format('Y-m'));

        $history = [];

        for ($month = $from->copy(); $month <= now(); $month->addMonth()) {
            $entries = $actuals->get($month->format('Y-m'), collect());
            $signed = fn (Flow $flow, float $amount): float => $flow->is_income ? $amount : -$amount;

            $history[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M'),
                'planned' => round((float) $entries->sum(fn (Actual $actual): float => $signed($leaves[$actual->flow_id], $leaves[$actual->flow_id]->plannedFor($month))), 2),
                'actual' => round((float) $entries->sum(fn (Actual $actual): float => $signed($leaves[$actual->flow_id], $actual->amount)), 2),
                'tracked' => $entries->count(),
            ];
        }

        return $history;
    }
}
