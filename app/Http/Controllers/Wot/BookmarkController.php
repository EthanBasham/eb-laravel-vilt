<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wot\SaveBookmarksRequest;
use App\Models\WotBookmark;

/**
 * The bookmarks bar under the header — the one thing on a World of Tanks page
 * that has nothing to do with Wargaming's data.
 *
 * One write, taking the whole list. See SaveBookmarksRequest for why.
 */
class BookmarkController extends Controller
{
    public function update(SaveBookmarksRequest $request): RedirectResponse
    {
        $user = $request->user();

        WotBookmark::replaceFor($user, $request->validated('bookmarks'));

        /*
         * Stamped even though replaceFor has just written the list, for the
         * case where it wrote nothing: a user who empties the bar before it was
         * ever seeded would otherwise be handed the ten defaults on the next
         * page load, having just explicitly cleared them.
         */
        if ($user->wot_bookmarks_seeded_at === null) {
            $user->forceFill(['wot_bookmarks_seeded_at' => now()])->save();
        }

        return back(fallback: route('wot.dashboard'));
    }
}
