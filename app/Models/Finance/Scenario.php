<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Database\Factories\Finance\ScenarioFactory;

/**
 * A named projection — "Optimistic", "Laid off at 58" — of every income and
 * expense from this year to the end of the plan.
 *
 * It holds no figures of its own, only what it changes about each flow; see
 * ScenarioFlow.
 *
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'name', 'description', 'bracket_inflation_rate'])]
class Scenario extends OwnedModel
{
    /** @use HasFactory<ScenarioFactory> */
    use HasFactory;

    protected $table = 'fin_scenarios';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bracket_inflation_rate' => 'float',
        ];
    }

    /**
     * What this scenario says about one flow, written whole. A rate of null
     * and no pinned years is the flow as it stands, so the row is removed
     * rather than kept empty. The years the rate starts again from are pinned
     * years, so there are none of those without a pin.
     *
     * @param  array<int|string, int|float|string>  $overrides  Amount by pinned year.
     * @param  list<int|string>  $restarts  The pinned years the rate starts again from.
     */
    public function adjustFlow(Flow $flow, ?float $rate, array $overrides, array $restarts = []): void
    {
        $overrides = static::pinnedYears($overrides);

        if ($rate === null && $overrides === []) {
            $this->scenarioFlows()->where('flow_id', $flow->id)->delete();

            return;
        }

        $restarts = collect($restarts)->map(fn (mixed $year): int => (int) $year)->sort()->values()->all();

        $this->scenarioFlows()->updateOrCreate(
            ['flow_id' => $flow->id],
            ['annual_growth_rate' => $rate, 'overrides' => $overrides ?: null, 'restarts' => $restarts ?: null],
        );
    }

    /**
     * What this scenario says about one holding, written whole. Nothing set
     * at all is the holding as it stands, so the row is removed rather than
     * kept empty.
     *
     * @param  array<int|string, int|float|string>  $overrides  Value by pinned year-end.
     */
    public function adjustHolding(Holding $holding, ?float $rate, ?float $contribution, array $overrides): void
    {
        $overrides = static::pinnedYears($overrides);

        if ($rate === null && $contribution === null && $overrides === []) {
            $this->scenarioHoldings()->where('holding_id', $holding->id)->delete();

            return;
        }

        $this->scenarioHoldings()->updateOrCreate(
            ['holding_id' => $holding->id],
            ['annual_rate' => $rate, 'monthly_contribution' => $contribution, 'overrides' => $overrides ?: null],
        );
    }

    /**
     * One rate across every income, or every expense, of the scenario's
     * owner. Pinned years are left where they are.
     *
     * The rate is written on the flows as they are listed. An item inside a
     * group is handed back to the group's rate instead of being given the
     * rate itself: it comes to the same figure today, and a rate set on the
     * group afterwards still reaches it.
     */
    public function setRateForDirection(string $direction, float $rate): void
    {
        $flows = Flow::query()->where('user_id', $this->user_id)->where('direction', $direction);

        DB::transaction(function () use ($flows, $rate): void {
            $rows = (clone $flows)->onlyTopLevel()->pluck('id')
                ->map(fn (int $flowId): array => ['scenario_id' => $this->id, 'flow_id' => $flowId, 'annual_growth_rate' => $rate])
                ->all();

            ScenarioFlow::query()->upsert($rows, ['scenario_id', 'flow_id'], ['annual_growth_rate']);

            $items = (clone $flows)->whereNotNull('parent_id')->pluck('id');

            // An item's row is kept only for the years it pins.
            $this->scenarioFlows()->whereIn('flow_id', $items)->whereNull('overrides')->delete();
            $this->scenarioFlows()->whereIn('flow_id', $items)->update(['annual_growth_rate' => null]);
        });
    }

    /**
     * A saved copy with every rate and pinned year carried over, as the
     * starting point for a variation.
     */
    public function duplicate(): static
    {
        return DB::transaction(function (): static {
            $copy = $this->replicate()->fill(['name' => static::copyName($this->name)]);
            $copy->save();

            // Each row replicated whole, so a setting added to either model
            // later is carried over without being named here.
            $this->scenarioFlows->each(fn (ScenarioFlow $row) => $copy->scenarioFlows()->save($row->replicate()));
            $this->scenarioHoldings->each(fn (ScenarioHolding $row) => $copy->scenarioHoldings()->save($row->replicate()));

            return $copy;
        });
    }

    /**
     * Pinned years as they are stored: each amount to the cent, in year order.
     *
     * @param  array<int|string, int|float|string>  $overrides
     * @return array<int|string, float>
     */
    private static function pinnedYears(array $overrides): array
    {
        return collect($overrides)->map(fn (mixed $amount): float => round((float) $amount, 2))->sortKeys()->all();
    }

    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            // Null follows the profile's inflation rate.
            'bracket_inflation_rate' => $this->bracket_inflation_rate,
        ]);
    }

    // Scopes

    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('name')->orderBy('id');
    }

    // Relationships

    /** @return HasMany<ScenarioFlow, $this> */
    public function scenarioFlows(): HasMany
    {
        return $this->hasMany(ScenarioFlow::class);
    }

    /** @return HasMany<ScenarioHolding, $this> */
    public function scenarioHoldings(): HasMany
    {
        return $this->hasMany(ScenarioHolding::class);
    }
}
