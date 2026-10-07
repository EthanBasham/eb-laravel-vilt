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

    /**
     * A route only ever binds the signed-in user's own row: anybody else's is
     * a 404, the same as one that does not exist, and before the request is
     * validated — so a form's error messages never describe another user's
     * record. The controllers still check isOwnedBy() as well; this is a
     * second lock on the same door, not a replacement for it.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?static
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        return $this->resolveRouteBindingQuery($this, $value, $field)->where($this->qualifyColumn('user_id'), $userId)->first();
    }

    /**
     * What a copy of something is called: its name with "copy" after it, cut
     * to what the name columns hold. A thing with no name has a copy with
     * none.
     */
    public static function copyName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return str("{$name} copy")->limit(80, '')->toString();
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
