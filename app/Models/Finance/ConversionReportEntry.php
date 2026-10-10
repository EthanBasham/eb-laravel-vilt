<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Database\Factories\Finance\ConversionReportEntryFactory;

/**
 * One column of the Roth report: a conversion strategy run on a projection.
 * A null `scenario_id` is the income and expenses as entered.
 *
 * The report is these and nothing else. A strategy is in it once for each
 * projection it is reported on, and not at all when it has none — so one
 * strategy can be set against itself across projections, several against
 * each other on the same one, and an edit to a strategy reaches every column
 * of it. Only what is in the report is simulated (ConversionBoard).
 *
 * @property-read string $label
 */
#[Fillable(['user_id', 'conversion_strategy_id', 'scenario_id'])]
class ConversionReportEntry extends OwnedModel
{
    /** @use HasFactory<ConversionReportEntryFactory> */
    use HasFactory;

    protected $table = 'fin_conversion_report_entries';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversion_strategy_id' => 'integer',
            'scenario_id' => 'integer',
        ];
    }

    /**
     * How many more columns a user's report will take: up to the most it
     * ever holds, or — for `default` — up to where strategies made in bulk
     * stop joining it by themselves.
     */
    public static function roomFor(User $user, string $limit = 'max'): int
    {
        return max(0, (int) config("finance.conversion_comparison.{$limit}") - static::query()->onlyOwnedBy($user)->count());
    }

    /**
     * Makes the report each of the strategies named on each of the
     * projections named, and nothing else: projection by projection, so the
     * strategies on the same one sit together.
     *
     * @param  list<int>  $strategyIds
     * @param  list<int|null>  $scenarioIds
     */
    public static function replace(User $user, array $strategyIds, array $scenarioIds): void
    {
        DB::transaction(function () use ($user, $strategyIds, $scenarioIds): void {
            static::query()->onlyOwnedBy($user)->delete();

            foreach ($scenarioIds as $scenarioId) {
                foreach ($strategyIds as $strategyId) {
                    static::query()->create(['user_id' => $user->id, 'conversion_strategy_id' => $strategyId, 'scenario_id' => $scenarioId]);
                }
            }
        });
    }

    /** What the column is called: its strategy, and the projection it is run on. */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->strategy->label.' · '.($this->scenario?->name ?? 'As entered'));
    }

    // Scopes

    /** As added, so a new column takes the next place and the next colour. */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    // Relationships

    /** @return BelongsTo<ConversionStrategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(ConversionStrategy::class, 'conversion_strategy_id');
    }

    /** @return BelongsTo<Scenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }
}
