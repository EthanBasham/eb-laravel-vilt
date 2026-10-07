<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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

    /**
     * Records what a flow came to in a month, replacing whatever was there.
     * A null amount clears the month.
     *
     * The row is found with whereDate rather than through updateOrCreate's
     * attribute match: a `date` column is not stored the same way by every
     * driver (SQLite keeps a time on it), so an equality match on the string
     * misses the existing row and the insert then trips the unique index.
     */
    public static function record(Flow $flow, Carbon $month, ?float $amount): void
    {
        $actual = static::query()->where('flow_id', $flow->id)->whereDate('month', $month)->first();

        if ($amount === null) {
            $actual?->delete();

            return;
        }

        if ($actual) {
            $actual->update(['amount' => $amount]);

            return;
        }

        static::query()->create(['user_id' => $flow->user_id, 'flow_id' => $flow->id, 'month' => $month, 'amount' => $amount]);
    }

    // Relationships

    /** @return BelongsTo<Flow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
