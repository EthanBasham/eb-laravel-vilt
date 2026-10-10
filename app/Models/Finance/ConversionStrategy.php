<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Database\Factories\Finance\ConversionStrategyFactory;

/**
 * One way of moving traditional money to Roth, and the assumptions it is run
 * under — but not the projection it is run on. That is chosen where the
 * strategy is put in the report (ConversionReportEntry), so one strategy can
 * be reported on several projections and is still one thing to edit.
 *
 * It holds settings only; the figures are worked out by ConversionBoard, and
 * only for the report's columns. A strategy in no report costs the page
 * nothing.
 *
 * @property-read bool $is_customized
 * @property-read string $kind_label
 * @property-read string $label
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'name', 'kind', 'convert_from_age', 'convert_until_age', 'fill_rate', 'conversion_amount', 'tax_payment', 'tax_outside_amount', 'overrides', 'inflation_rate', 'growth_rate', 'heir_is_charity', 'heir_income'])]
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
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'convert_from_age' => 'integer',
            'convert_until_age' => 'integer',
            'fill_rate' => 'float',
            'conversion_amount' => 'float',
            'tax_outside_amount' => 'float',
            'overrides' => 'array',
            'inflation_rate' => 'float',
            'growth_rate' => 'float',
            'heir_is_charity' => 'boolean',
            'heir_income' => 'float',
        ];
    }

    /**
     * One strategy of each kind, on every default, put in the report on each
     * projection given — null standing for the income and expenses as
     * entered. A kind's strategy is made only if the user has none still on
     * every default, so asking again for another projection adds columns
     * rather than a second set of the same strategies.
     *
     * The columns join the report while it is short of where it stops
     * filling by itself; the rest are left to be added by hand.
     *
     * @param  Collection<int, Scenario|null>  $projections
     * @return Collection<int, ConversionReportEntry> The columns added.
     */
    public static function createStarters(User $user, Collection $projections): Collection
    {
        return DB::transaction(function () use ($user, $projections): Collection {
            $starters = collect(config('finance.conversion_strategies'))->map(fn (array $kind, string $key): static => static::query()->firstOrCreate([
                'user_id' => $user->id,
                'kind' => $key,
                'name' => null,
                'convert_from_age' => null,
                'convert_until_age' => null,
                'fill_rate' => null,
                'conversion_amount' => ($kind['amount'] ?? false) ? config('finance.defaults.conversion_amount') : null,
                'tax_payment' => 'outside',
                'tax_outside_amount' => null,
                'overrides' => null,
                'inflation_rate' => null,
                'growth_rate' => null,
                'heir_is_charity' => false,
                'heir_income' => config('finance.defaults.heir_income'),
            ]));

            return $projections->flatMap(fn (?Scenario $scenario): Collection => $starters->flatMap(
                fn (ConversionStrategy $strategy): Collection => $strategy->addToReport([$scenario?->id], 'default'),
            ))->values();
        });
    }

    /**
     * Puts it in the report on each projection named that it is not already
     * reported on, for as long as the report has room. Null is the income
     * and expenses as entered.
     *
     * @param  array<int, int|string|null>  $scenarioIds
     * @param  string  $limit  Which of the report's two limits is room: see ConversionReportEntry::roomFor().
     * @return Collection<int, ConversionReportEntry> The columns added.
     */
    public function addToReport(array $scenarioIds, string $limit = 'max'): Collection
    {
        $room = ConversionReportEntry::roomFor($this->user, $limit);
        $already = $this->reportEntries()->pluck('scenario_id')->all();

        return collect($scenarioIds)
            ->map(fn (int|string|null $id): ?int => $id === null ? null : (int) $id)
            ->unique()
            ->reject(fn (?int $id): bool => in_array($id, $already, true))
            ->take($room)
            ->map(fn (?int $id): ConversionReportEntry => $this->reportEntries()->create(['user_id' => $this->user_id, 'scenario_id' => $id]))
            ->values();
    }

    /**
     * A saved copy, reported on the same projections as the one it was made
     * from for as long as the report has room.
     */
    public function duplicate(): static
    {
        return DB::transaction(function (): static {
            $copy = $this->replicate()->fill(['name' => static::copyName($this->name)]);
            $copy->save();
            $copy->addToReport($this->reportEntries()->inDefaultOrder()->pluck('scenario_id')->all());

            return $copy;
        });
    }

    /** Whether any year of it has been set by hand, over what its kind would do. */
    protected function isCustomized(): Attribute
    {
        return Attribute::get(fn (): bool => ! empty($this->overrides));
    }

    protected function kindLabel(): Attribute
    {
        return Attribute::get(fn (): string => config("finance.conversion_strategies.{$this->kind}.label", $this->kind));
    }

    /**
     * What it is called: its own name, or — as a name is optional — its
     * kind, marked `[C]` once any year of it has been set by hand.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->name ?? $this->kind_label.($this->is_customized ? ' [C]' : ''));
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
            'convert_from_age' => $this->convert_from_age,
            'convert_until_age' => $this->convert_until_age,
            'fill_rate' => $this->fill_rate,
            'conversion_amount' => $this->conversion_amount,
            'tax_payment' => $this->tax_payment,
            'tax_outside_amount' => $this->tax_outside_amount,
            'overrides' => $this->overrides,
            'is_customized' => $this->is_customized,
            'inflation_rate' => $this->inflation_rate,
            'growth_rate' => $this->growth_rate,
            'heir_is_charity' => $this->heir_is_charity,
            'heir_income' => $this->heir_income,
        ]);
    }

    // Scopes

    /** As added. */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    // Relationships

    /** @return HasMany<ConversionReportEntry, $this> */
    public function reportEntries(): HasMany
    {
        return $this->hasMany(ConversionReportEntry::class);
    }
}
