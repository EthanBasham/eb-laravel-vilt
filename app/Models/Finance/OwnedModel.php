<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Model;
use App\Models\User;

/**
 * Base for every Financial Fleet model that belongs to one user.
 *
 * The ownership scope lives here rather than as relationships on User, which
 * is deliberate: nothing in the sub-project is referenced from a shared file,
 * so deleting app/Models/Finance takes all of it without leaving a dangling
 * `hasMany` behind on the user.
 */
abstract class OwnedModel extends Model
{
    /**
     * Whether this row is the given user's. Every write action guards on it.
     */
    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }

    // Scopes

    public function scopeOnlyOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where($query->qualifyColumn('user_id'), $user->id);
    }

    // Relationships

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
