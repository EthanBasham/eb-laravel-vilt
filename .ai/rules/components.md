---
paths:
  - 'app/Models/WotBookmark.php, app/Http/Controllers/Wot/BookmarkController.php, app/Http/Requests/Wot/SaveBookmarksRequest.php, config/wotbookmarks.php, resources/js/wot/Components/Bookmark*.vue'
---

# Components

## Bookmarks seed once, guarded by a timestamp — never by the row count
`config('wotbookmarks.defaults')` is written out as the user's own `wot_bookmarks` rows the first time their bar is read, and `users.wot_bookmarks_seeded_at` records that it happened. Guard the seed on that column, never on `bookmarks()->count() === 0`: an empty bar is a deliberate state, and a count check would hand the ten defaults back the moment someone deletes the last bookmark. BookmarkController stamps it on save as well, for the user who empties the bar before ever being seeded.

Consequence: editing the config only affects accounts that have never loaded /wot. It is the starting list, not the live one.

The editor posts the whole list and `WotBookmark::replaceFor()` deletes and rewrites — no id survives a save, so never add a route or prop that addresses a bookmark by id.

URLs validate as `url:http,https`. Plain `url` passes `javascript:` and `data:`, which is stored XSS in the href the bar renders for the person who typed it.
