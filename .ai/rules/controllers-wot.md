---
paths:
  - 'app/Models/Wot{Article,Event}.php,app/Http/Controllers/Wot/NewsController.php,app/Http/Controllers/Wot/CalendarController.php'
---

# Controllers Wot

## Read per-user article and event state through scopes, not the User relationships
Pins, seen articles and ignored events live in per-user pivot tables. Query them through WotArticle/WotEvent scopes, never through the User relations.

Writes are methods on the article or event, named for the act and taking the user: `$article->pinBy($user)`, `$article->unpinBy($user)`, `$article->markSeenBy($user)`, `WotArticle::markAllSeenBy($user)` (static, since there is no one article). A controller delegates and names no table and no pivot. Inside those methods the write goes through the model's own side of the relation — `$this->pinnedBy()`, not `$user->pinnedArticles()` — because the write belongs to the side the method hangs off.

None of the three pivot tables has a model, deliberately: they are presence-only rows. A statement that cannot go through a relation uses `DB::table()` inside the owning model (WotArticle for views, WotEvent for ignores), never in a controller.

Naming: `*By($user)` filters and takes a non-nullable User (`onlyPinnedBy`, `onlySeenBy`, `notSeenBy`, `notIgnoredBy`); `*For($user)` only decorates the rows with the pivot timestamp and takes `?User` (`withPinnedFor`, `withSeenFor`, `withIgnoredFor`). Exclusion is `not*`, never `onlyUn*`.

The reason: a read on the User side grows its own filtering and ordering that then contradicts the scopes. `pinnedArticles()` carried an `orderByPivot('pinned_at','desc')` that fought scopePinnedFirstFor(), which deliberately orders pins by published_at.

`notSeenBy($user)` is what a read of the backlog uses, and what keeps `markAllSeen()`'s insert to the rows that are actually new. It is no longer what makes the write *safe* — see the rule below.

## Seen rows are written by one conflict-tolerant statement, never read-then-attach
The writes live on WotArticle — `$article->markSeenBy($user)` uses insertOrIgnore() for its single row, `WotArticle::markAllSeenBy($user)` uses insertOrIgnoreUsing() as one INSERT ... SELECT. NewsController delegates and holds no table name, the way BookmarkController delegates to WotBookmark::replaceFor(). Both rely on the unique index on wot_article_views(user_id, wot_article_id) to decide per row, so an existing row is skipped rather than updated — which is what preserves "first seen". Do not go back to plucking ids and attach()ing them: that shape is check-then-act, and two overlapping requests from one user (a double-clicked mark-all, or a card's own mark landing mid-flight) both read the same ids and the second insert dies on the unique index. attach()/syncWithoutDetaching() are still wrong here for the original reason too — the latter calls updateExistingPivot and would rewrite seen_at. notSeenBy() stays in markAllSeen's SELECT to keep the inserted set minimal, not for safety. The constant columns are bound via selectRaw, whose bindings land in the `select` group ahead of notSeenBy()'s `where` binding; a wrong order writes one column's value into another silently, so ArticleSeenTest asserts the stored seen_at and user_id, not just row counts.
