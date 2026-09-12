<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many recruits of one kind are waiting in the barracks.
 *
 * The kind is a key from config('wargaming.crew_recruits') rather than a column
 * per sort of recruit, so the list the page renders and the list the database
 * holds are the same list. A kind with no row is a zero.
 */
#[Fillable(['wot_account_id', 'recruit_key', 'quantity'])]
class WotCrewRecruit extends Model
{
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
