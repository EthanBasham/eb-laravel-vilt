<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a flow actually came to in one month. `month` is the first of it.
 */
#[Fillable(['user_id', 'flow_id', 'month', 'amount'])]
class Actual extends OwnedModel
{
    protected $table = 'fin_actuals';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => 'float',
        ];
    }

    // Relationships

    /** @return BelongsTo<Flow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
