<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Model;

/**
 * What one scenario changes about one holding: the rate it grows (or accrues)
 * at, what goes into it each month, and the year-ends pinned to a value.
 *
 * Not an OwnedModel, for the reason ScenarioFlow is not: it belongs to whoever
 * owns its scenario. A holding with no row here is projected as it stands.
 */
#[Fillable(['scenario_id', 'holding_id', 'annual_rate', 'monthly_contribution', 'overrides'])]
class ScenarioHolding extends Model
{
    protected $table = 'fin_scenario_holdings';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annual_rate' => 'float',
            'monthly_contribution' => 'float',
            'overrides' => 'array',
        ];
    }

    // Relationships

    /** @return BelongsTo<Scenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /** @return BelongsTo<Holding, $this> */
    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
