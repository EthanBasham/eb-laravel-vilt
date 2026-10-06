<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Model;
use Database\Factories\Finance\ScenarioFlowFactory;

/**
 * What one scenario changes about one flow: the rate it grows at, the years
 * pinned to an amount of their own, and which of those the rate starts again
 * from (`restarts`, a list of pinned years).
 *
 * Not an OwnedModel: it has no `user_id`, and belongs to whoever owns its
 * scenario. A flow with no row here is projected as it stands.
 */
#[Fillable(['scenario_id', 'flow_id', 'annual_growth_rate', 'overrides', 'restarts'])]
class ScenarioFlow extends Model
{
    /** @use HasFactory<ScenarioFlowFactory> */
    use HasFactory;

    protected $table = 'fin_scenario_flows';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annual_growth_rate' => 'float',
            'overrides' => 'array',
            'restarts' => 'array',
        ];
    }

    // Relationships

    /** @return BelongsTo<Scenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /** @return BelongsTo<Flow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
