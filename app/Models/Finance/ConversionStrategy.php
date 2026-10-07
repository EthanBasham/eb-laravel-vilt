<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Database\Factories\Finance\ConversionStrategyFactory;

/**
 * One way of moving traditional money to Roth, and the assumptions it is run
 * under. The Retirement Strategizer runs each and sets them side by side.
 *
 * It holds settings only; the figures are worked out by ConversionBoard —
 * and only for the strategies being compared (`is_compared`). The rest wait
 * in the holding area, costing the page nothing.
 *
 * @property-read string $kind_label
 * @property-read string $label
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'scenario_id', 'name', 'kind', 'is_compared', 'convert_from_age', 'convert_until_age', 'fill_rate', 'conversion_amount', 'tax_payment', 'tax_outside_amount', 'inflation_rate', 'growth_rate', 'heir_is_charity', 'heir_income'])]
class ConversionStrategy extends OwnedModel
{
    /** @use HasFactory<ConversionStrategyFactory> */
    use HasFactory;

    protected $table = 'fin_conversion_strategies';

    /**
     * A conversion's tax is paid from outside it unless the strategy says otherwise.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tax_payment' => 'outside',
        'is_compared' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_compared' => 'boolean',
            'convert_from_age' => 'integer',
            'convert_until_age' => 'integer',
            'fill_rate' => 'float',
            'conversion_amount' => 'float',
            'tax_outside_amount' => 'float',
            'inflation_rate' => 'float',
            'growth_rate' => 'float',
            'heir_is_charity' => 'boolean',
            'heir_income' => 'float',
        ];
    }

    /**
     * Whether a user's comparison still has room for a newly made strategy.
     * Past that it goes to the holding area, to be brought in by hand.
     */
    public static function hasRoomToCompare(User $user): bool
    {
        return static::query()->onlyOwnedBy($user)->onlyCompared()->count() < (int) config('finance.conversion_comparison.default');
    }

    /**
     * Whether a user's report holds as many strategies as it ever can, so
     * that not even one brought in by hand fits.
     */
    public static function comparisonIsFull(User $user): bool
    {
        return static::query()->onlyOwnedBy($user)->onlyCompared()->count() >= (int) config('finance.conversion_comparison.max');
    }

    /**
     * One strategy of each kind, on every default, for each projection
     * given — null standing for the income and expenses as entered. Each
     * joins the comparison while it has room and is held after, like any new
     * strategy.
     *
     * @param  Collection<int, Scenario|null>  $projections
     * @return Collection<int, static>
     */
    public static function createStarters(User $user, Collection $projections): Collection
    {
        return DB::transaction(function () use ($user, $projections): Collection {
            $room = (int) config('finance.conversion_comparison.default') - static::query()->onlyOwnedBy($user)->onlyCompared()->count();
            $made = collect();

            foreach ($projections as $scenario) {
                foreach (config('finance.conversion_strategies') as $key => $kind) {
                    $made->push(static::query()->create([
                        'user_id' => $user->id,
                        'scenario_id' => $scenario?->id,
                        'kind' => $key,
                        'is_compared' => $room-- > 0,
                        'conversion_amount' => ($kind['amount'] ?? false) ? config('finance.defaults.conversion_amount') : null,
                        'heir_income' => config('finance.defaults.heir_income'),
                    ]));
                }
            }

            return $made;
        });
    }

    /**
     * Makes the strategies named the whole comparison: every other one of
     * the user's goes to the holding area.
     *
     * @param  list<int>  $ids
     */
    public static function replaceComparison(User $user, array $ids): void
    {
        DB::transaction(function () use ($user, $ids): void {
            static::query()->onlyOwnedBy($user)->whereKeyNot($ids)->update(['is_compared' => false]);
            static::query()->onlyOwnedBy($user)->whereKey($ids)->update(['is_compared' => true]);
        });
    }

    /**
     * A saved copy, beside the one it was made from: in the report for one
     * in the report, held for one held. Only a report already at its most
     * sends a copy made there to the holding area instead.
     */
    public function duplicate(): static
    {
        $copy = $this->replicate()->fill([
            'name' => static::copyName($this->name),
            'is_compared' => $this->is_compared && ! static::comparisonIsFull($this->user),
        ]);
        $copy->save();

        return $copy;
    }

    protected function kindLabel(): Attribute
    {
        return Attribute::get(fn (): string => config("finance.conversion_strategies.{$this->kind}.label", $this->kind));
    }

    /**
     * What it is called: its own name, or — as a name is optional — the
     * projection it runs on and its kind.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->name ?? ($this->scenario?->name ?? 'As entered').' · '.$this->kind_label);
    }

    /** The settings as saved, nulls and all: what the edit form is filled from. */
    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'kind' => $this->kind,
            'kind_label' => $this->kind_label,
            'is_compared' => $this->is_compared,
            'scenario_id' => $this->scenario_id,
            'convert_from_age' => $this->convert_from_age,
            'convert_until_age' => $this->convert_until_age,
            'fill_rate' => $this->fill_rate,
            'conversion_amount' => $this->conversion_amount,
            'tax_payment' => $this->tax_payment,
            'tax_outside_amount' => $this->tax_outside_amount,
            'inflation_rate' => $this->inflation_rate,
            'growth_rate' => $this->growth_rate,
            'heir_is_charity' => $this->heir_is_charity,
            'heir_income' => $this->heir_income,
        ]);
    }

    // Scopes

    /** The strategies set side by side: the only ones the page simulates. */
    public function scopeOnlyCompared(Builder $query): Builder
    {
        return $query->where('is_compared', true);
    }

    /** The strategies waiting in the holding area. */
    public function scopeNotCompared(Builder $query): Builder
    {
        return $query->where('is_compared', false);
    }

    /** As added, so a new strategy takes the next column and the next colour. */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    // Relationships

    /** @return BelongsTo<Scenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }
}
