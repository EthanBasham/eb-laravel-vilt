<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Database\Factories\Finance\WithdrawalStrategyFactory;

/**
 * One way of drawing the retirement accounts down: the order the buckets are
 * emptied in, what a year sets out to spend, and the assumptions it is run
 * under.
 *
 * Settings only; the figures are worked out by WithdrawalBoard.
 *
 * @property-read string $kind_label
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'scenario_id', 'name', 'kind', 'fill_rate', 'spending_rule', 'spending_amount', 'spending_percent', 'inflation_rate', 'growth_rate'])]
class WithdrawalStrategy extends OwnedModel
{
    /** @use HasFactory<WithdrawalStrategyFactory> */
    use HasFactory;

    protected $table = 'fin_withdrawal_strategies';

    /**
     * A strategy spends what the projection says unless it sets a rule.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'spending_rule' => 'projection',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fill_rate' => 'float',
            'spending_amount' => 'float',
            'spending_percent' => 'float',
            'inflation_rate' => 'float',
            'growth_rate' => 'float',
        ];
    }

    protected function kindLabel(): Attribute
    {
        return Attribute::get(fn (): string => config("finance.withdrawal_strategies.{$this->kind}.label", $this->kind));
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
            'fill_rate' => $this->fill_rate,
            'spending_rule' => $this->spending_rule,
            'spending_amount' => $this->spending_amount,
            'spending_percent' => $this->spending_percent,
            'inflation_rate' => $this->inflation_rate,
            'growth_rate' => $this->growth_rate,
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
