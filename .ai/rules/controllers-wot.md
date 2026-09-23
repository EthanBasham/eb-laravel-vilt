---
paths:
  - 'app/Models/Wot{Article,Event}.php,app/Http/Controllers/Wot/NewsController.php'
---

# Controllers Wot

## Read per-user article and event state through scopes, not the User relationships
Pins, seen articles and ignored events live in per-user pivot tables. Query them through WotArticle/WotEvent scopes; reach for `$user->pinnedArticles()` / `seenArticles()` / `ignoredEvents()` only to write (attach, detach, syncWithoutDetaching).

Naming: `*By($user)` filters and takes a non-nullable User (`onlyPinnedBy`, `onlySeenBy`, `notSeenBy`, `notIgnoredBy`); `*For($user)` only decorates the rows with the pivot timestamp and takes `?User` (`withPinnedFor`, `withSeenFor`, `withIgnoredFor`). Exclusion is `not*`, never `onlyUn*`.

The reason: a read on the User side grows its own filtering and ordering that then contradicts the scopes. `pinnedArticles()` carried an `orderByPivot('pinned_at','desc')` that fought scopePinnedFirstFor(), which deliberately orders pins by published_at.

`notSeenBy($user)` is what a read of the backlog uses, and what keeps `markAllSeen()`'s insert to the rows that are actually new. It is no longer what makes the write *safe* — see the rule below.

## Seen rows are written by one conflict-tolerant statement, never read-then-attach
markSeen() uses insertOrIgnore() for its single row; markAllSeen() uses insertOrIgnoreUsing() as one INSERT ... SELECT. Both rely on the unique index on wot_article_views(user_id, wot_article_id) to decide per row, so an existing row is skipped rather than updated — which is what preserves "first seen". Do not go back to plucking ids and attach()ing them: that shape is check-then-act, and two overlapping requests from one user (a double-clicked mark-all, or a card's own mark landing mid-flight) both read the same ids and the second insert dies on the unique index. attach()/syncWithoutDetaching() are still wrong here for the original reason too — the latter calls updateExistingPivot and would rewrite seen_at. notSeenBy() stays in markAllSeen's SELECT to keep the inserted set minimal, not for safety. The constant columns are bound via selectRaw, whose bindings land in the `select` group ahead of notSeenBy()'s `where` binding; a wrong order writes one column's value into another silently, so ArticleSeenTest asserts the stored seen_at and user_id, not just row counts.
