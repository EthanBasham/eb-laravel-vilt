<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
}
