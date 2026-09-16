<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One link in the bar under the World of Tanks header.
 *
 * @property-read array{label: string, url: string, title: ?string} $bar_entry
 */
#[Fillable(['user_id', 'label', 'url', 'title', 'position'])]
class WotBookmark extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * This user's bar, seeding the defaults the first time they are asked for.
     *
     * The seed is guarded by `wot_bookmarks_seeded_at` rather than by the row
     * count, so emptying the bar empties it for good. A user who has been
     * seeded and holds no rows has chosen that.
     *
     * @return array<int, array{label: string, url: string, title: ?string}>
     */
    public static function forUser(User $user): array
    {
        if ($user->wot_bookmarks_seeded_at === null) {
            static::seedDefaults($user);
        }

        return $user->bookmarks()->inDefaultOrder()->get()
            ->map(fn (self $bookmark): array => $bookmark->bar_entry)
            ->all();
    }

    /**
     * Writes config('wotbookmarks.defaults') out as this user's own rows.
     *
     * Once this has run the config is never read for them again — the list is
     * theirs to edit, and a later change to the defaults should not reach back
     * into a bar someone has already arranged.
     */
    public static function seedDefaults(User $user): void
    {
        DB::transaction(function () use ($user): void {
            static::replaceFor($user, (array) config('wotbookmarks.defaults'));

            $user->forceFill(['wot_bookmarks_seeded_at' => now()])->save();
        });
    }

    /**
     * Rewrites a user's whole bar from the posted list.
     *
     * Replace rather than reconcile: the editor sends the bar as it should
     * stand, including its order, so matching rows up to decide which moved
     * would be work in service of ids nothing refers to.
     *
     * @param  array<int, array{label: string, url: string, title?: ?string}>  $bookmarks
     */
    public static function replaceFor(User $user, array $bookmarks): void
    {
        DB::transaction(function () use ($user, $bookmarks): void {
            $user->bookmarks()->delete();

            $user->bookmarks()->createMany(
                collect($bookmarks)->values()->map(fn (array $bookmark, int $index): array => [
                    'label' => $bookmark['label'],
                    'url' => $bookmark['url'],
                    'title' => $bookmark['title'] ?? null,
                    'position' => $index,
                ])->all(),
            );
        });
    }

    /**
     * What the bar is handed for this row — deliberately not the model.
     *
     * The prop shape predates the table (the bar shipped reading config) and
     * the ids behind it are not addressable by any route, so exposing them
     * would only invite a client to start sending them back.
     *
     * @return array{label: string, url: string, title: ?string}
     */
    protected function barEntry(): Attribute
    {
        return Attribute::get(fn (): array => [
            'label' => $this->label,
            'url' => $this->url,
            'title' => $this->title,
        ]);
    }

    // Scopes

    /**
     * The order the bar prints, which is the order the editor left it in.
     *
     * `id` breaks ties so that rows sharing a position — only possible from a
     * hand-written row, since the editor renumbers the lot — stay put rather
     * than shuffling between requests.
     */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    // Relationships

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
