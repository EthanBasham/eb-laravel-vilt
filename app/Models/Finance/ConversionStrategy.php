<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Database\Factories\Finance\ConversionStrategyFactory;

/**
 * One way of moving traditional money to Roth, and the assumptions it is run
 * under. The Retirement Strategizer runs each and sets them side by side.
 *
 * It holds settings only; the figures are worked out by ConversionBoard.
 *
 * @property-read string $kind_label
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'scenario_id', 'name', 'kind', 'convert_from_age', 'convert_until_age', 'fill_rate', 'tax_payment', 'tax_outside_amount', 'inflation_rate', 'growth_rate', 'heir_is_charity', 'heir_income'])]
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
            'tax_outside_amount' => 'float',
            'inflation_rate' => 'float',
            'growth_rate' => 'float',
            'heir_is_charity' => 'boolean',
            'heir_income' => 'float',
        ];
    }

    protected function kindLabel(): Attribute
    {
        return Attribute::get(fn (): string => config("finance.conversion_strategies.{$this->kind}.label", $this->kind));
    }

    /** The settings as saved, nulls and all: what the edit form is filled from. */
    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'kind_label' => $this->kind_label,
            'scenario_id' => $this->scenario_id,
            'convert_from_age' => $this->convert_from_age,
            'convert_until_age' => $this->convert_until_age,
            'fill_rate' => $this->fill_rate,
            'tax_payment' => $this->tax_payment,
            'tax_outside_amount' => $this->tax_outside_amount,
            'inflation_rate' => $this->inflation_rate,
            'growth_rate' => $this->growth_rate,
            'heir_is_charity' => $this->heir_is_charity,
            'heir_income' => $this->heir_income,
        ]);
    }

    // Scopes

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
