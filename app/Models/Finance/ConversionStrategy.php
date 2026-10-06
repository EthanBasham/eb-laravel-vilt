<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
