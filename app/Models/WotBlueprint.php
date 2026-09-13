<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many blueprints of one nation are held, or universal ones.
 *
 * The raw material fragments are built from — not the fragments themselves,
 * which live against a vehicle on wot_tank_purchases. A universal stack is
 * stored as the nation 'universal' rather than as a null; see the migration.
 */
#[Fillable(['wot_account_id', 'nation', 'quantity'])]
class WotBlueprint extends Model
{
    /** The nation slug standing in for blueprints that spend anywhere. */
    public const UNIVERSAL = 'universal';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
