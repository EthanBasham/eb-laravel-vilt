<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many books of one type, for one nation, are held.
 *
 * Books come tied to a nation or spend anywhere, and the second is stored as
 * the nation 'universal' rather than as a null — see the migration for why the
 * unique index needs a real value there.
 *
 * The two special items are the same shape with no nation to speak of, so they
 * are held here too, under 'universal'.
 */
#[Fillable(['wot_account_id', 'book_type', 'nation', 'quantity'])]
class WotCrewBook extends Model
{
    /** The nation slug standing in for a book that spends anywhere. */
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
