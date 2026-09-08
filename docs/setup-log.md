# Setup Log — laravel-vilt

A running, append-only record of how this project was scaffolded and configured: what was
installed, what was decided, and *why* — especially where the rationale isn't obvious from
the code itself.

**Append to this as you go. Don't rewrite it retroactively.** If a later change reverses an
earlier decision, add a new dated entry saying so rather than editing the old one — the
history of what was tried is as useful as the current state.

Conventions here deliberately mirror `~/projects/eb-portfolio`, which is the same
developer's existing Laravel 13 project; its `.claude/setup-log.md` and `CLAUDE.md` are the
reference for structure, tooling choices, and code style.

> **Note on location:** `eb-portfolio` keeps its log at `.claude/setup-log.md`, but `.claude/`
> is gitignored there. This project puts the log at `docs/setup-log.md` so it's tracked in
> version control, per the brief for this project.

---

## 2026-09-08 — Environment recon

Verified the toolchain already present on this WSL2 machine (all installed previously for
`eb-portfolio`, so nothing new needed here):

```
PHP        8.3.6 (cli, NTS, OPcache)
Composer   2.10.2
Node       v24.18.1  /  npm 11.16.0   (via nvm)
PostgreSQL 16.15 (Ubuntu), cluster running on :5432
laravel    installer at ~/.config/composer/vendor/bin/laravel
```

Latest stable versions resolved at scaffold time:

| package | latest stable |
|---|---|
| laravel/framework | 13.31.0 |
| laravel/breeze | 2.4.2 |
| tailwindcss | 4.3.3 |
| vite | 8.2.2 |
| laravel-vite-plugin | 3.2.0 |
| sass | 1.104.0 |
| jquery | **4.0.0** |
| concurrently | 10.0.5 |

### Version decisions worth flagging

**jQuery 4.0.0, not 3.7.1.** The brief asks for latest stable and npm's `latest` dist-tag is
`4.0.0` (`beta` is still on `4.0.0-rc.2`, i.e. 4.0.0 final has shipped). `eb-portfolio` pins
3.7.1, so the two projects intentionally differ. jQuery 4 drops IE support and removes
long-deprecated APIs (`$.isArray`, `$.trim`, `.bind()`/`.unbind()`, `.delegate()`, etc.); none
of those are used here. Noted so a future session doesn't "fix" the mismatch by downgrading.

**Tailwind v4 (CSS-first), so no `tailwind.config.js`.** The brief asked for a
`tailwind.config.js` that scans template paths, but that's the v3 model. v4 — the latest
stable, which the brief also asks for — replaces it with `@theme` blocks in the CSS entry plus
automatic content detection. `eb-portfolio` hit this same conflict and resolved it the same
way, explicitly rejecting the `@config` compatibility bridge. Design tokens therefore live in
`resources/css/app.css`.

**Auth = Laravel Breeze, Blade stack.** The brief left this as an unresolved
`[Breeze / built-in / custom]` bracket. Breeze/Blade matches `eb-portfolio` and is the
framework-blessed option, so it wins over hand-rolling.

**Alpine.js removed.** Breeze's Blade stack ships Alpine and uses it for the nav dropdown and
responsive menu. Alpine isn't in this project's stack list and the brief says not to add
dependencies beyond it, so the Alpine-driven markup gets rewritten in jQuery.
(`eb-portfolio` kept Alpine; this project deliberately does not.)

---

## 2026-09-08 — Project scaffold

```bash
laravel new laravel-vilt --force --no-interaction --database=pgsql --pest \
    --no-authentication --no-node --git --branch=main --boost
```

- `--force` because `~/projects/laravel-vilt` already existed (empty, but the installer
  still refuses).
- `--no-authentication` gives the plain skeleton — Breeze is installed separately below
  rather than via a starter kit, since the starter kits are all Livewire/Inertia/React/Vue.
- `--no-node` so the front-end toolchain gets set up deliberately (Tailwind v4 + SASS +
  jQuery) instead of installing the default set and then tearing half of it out.
- `--boost` installs Laravel Boost (first-party): the MCP server in `.mcp.json`, generated
  guidelines in `CLAUDE.md`/`AGENTS.md`, and skills under `.claude/skills/`. Same setup as
  `eb-portfolio`.

**Two things `--force` does that are worth knowing.** It *overwrites* the target directory —
this log's first entry had already been written to `docs/` and was silently destroyed, so it
had to be reconstructed. Write nothing into the project directory before the installer runs.

**`--branch=main` still doesn't work.** The repo came out on `master` with no commits, the
same installer bug `eb-portfolio` hit. Renamed with `git branch -m master main` — safe on an
unborn branch since there's no history to rewrite. (No commit made; commits happen only when
explicitly asked for.)

### Local PostgreSQL

Created a dedicated role and database rather than reusing `eb-portfolio`'s, so the two
projects can't migrate over each other:

```bash
sudo -u postgres psql -c "CREATE ROLE laravel_vilt WITH LOGIN PASSWORD '<generated>';" \
                      -c "CREATE DATABASE laravel_vilt OWNER laravel_vilt;"
```

The generated password is stored at **`~/secrets/laravel-vilt-local-db-password`**
(`chmod 600`), per the standing convention that any generated credential gets persisted
outside the repo rather than only shown once in a terminal. The value itself is never
written into a tracked file — `.env` has it, and `.env` is gitignored.

Verified the role can connect over TCP with password auth, then `php artisan migrate` —
`users`, `cache`, `jobs` tables created.

---

## 2026-09-08 — Auth: Laravel Breeze (Blade)

```bash
composer require laravel/breeze --dev --no-interaction
php artisan breeze:install blade --pest --no-interaction
```

Gives login / register / forgot-password / reset-password / verify-email / confirm-password,
`routes/auth.php`, a `ProfileController` with account update+delete, and the
`auth`/`verified` middleware wiring. `--pest` so the generated auth tests match the
project's test runner.

Note that `breeze:install` also ran `npm install && npm run build` on the way through, which
is what pulled in the Tailwind **v3** toolchain replaced in the next entry. Unlike
`eb-portfolio`, npm worked here in the non-interactive shell (nvm was already sourced).

---

## 2026-09-08 — Tailwind v4, SASS, and jQuery wired into Vite

Breeze's Blade stack still ships the **Tailwind v3** setup — `tailwind.config.js`,
`postcss.config.js`, `autoprefixer`, and `@tailwind base/components/utilities` in the CSS
entry — even on Laravel 13, whose own skeleton had already been on v4. Since the brief asks
for latest-stable, upgraded to v4 properly rather than leaving a v3 island:

```bash
npm uninstall tailwindcss postcss autoprefixer alpinejs
rm tailwind.config.js postcss.config.js
npm install -D tailwindcss@4.3.3 @tailwindcss/vite@4.3.3 @tailwindcss/forms@0.5.11 \
    sass@1.104.0 jquery@4.0.0 vite@8.2.2 laravel-vite-plugin@3.2.0 concurrently@10.0.5
```

**No `@config` bridge.** v4 can be pointed at a legacy `tailwind.config.js`, but that keeps
the project on the v3 mental model indefinitely. Tokens live in `@theme` in
`resources/css/app.css` and become utilities automatically (`bg-brand-600`, `font-sans`).
Same call `eb-portfolio` made.

**Content scanning.** v4 has no `content: []` array — it walks the project root and infers
template files, so Blade/JS paths need no configuration at all. The one gap is that it
honours `.gitignore`, and `vendor/` is ignored, so Laravel's pagination views need an
explicit `@source` line in `app.css` or their classes get purged.

**Alpine.js removed** (see the recon entry for why). Four things depended on it; each was
rewritten:

| Alpine thing | Replaced with |
|---|---|
| `x-dropdown` component | `.dropdown` / `.dropdown__trigger` / `.dropdown__menu`, jQuery toggles `.is-open` |
| `x-modal` component | native `<dialog>` + `showModal()`, driven by `data-modal-open`/`data-modal-close` |
| `layouts/navigation.blade.php` | replaced wholesale by `partials/header.blade.php` |
| `x-data="{show:true}"` "Saved." flashes | `.auto-dismiss[data-dismiss-after]`, faded by a CSS transition |

The modal swap is a net simplification worth calling out: Breeze's Alpine modal hand-rolled
a focus trap, Escape handling, tab cycling, and body-scroll locking — roughly 40 lines of
inline expression. The native `<dialog>` element provides all of that, plus top-layer
stacking and `::backdrop`, so the jQuery version is two event handlers.

**SASS structure** under `resources/sass/`, using Sass's modern `@use` (not the deprecated
`@import`):

```
app.scss          → @use 'base'; @use 'components';
_variables.scss   → durations, z-index scale, breakpoints (SASS-only tokens)
_mixins.scss      → respond-above(), visually-hidden(), motion-safe()
_base.scss        → element defaults: ::selection, :focus-visible, scroll-behavior
_components.scss  → .nav-menu, .dropdown, .modal, .auto-dismiss, .milestone
```

The `_variables.scss` / `@theme` split is deliberate and documented in the file itself: if a
Blade template would ever want the value as a class it belongs in `@theme`; if only a `.scss`
rule needs it, it belongs in `_variables.scss`. Keeping colors in both places is how they
drift.

**Fonts.** Breeze's layouts link out to `fonts.bunny.net` for Figtree. Swapped that for
Laravel 13's built-in `bunny()` helper from `laravel-vite-plugin/fonts` plus the `@fonts`
Blade directive, which downloads Instrument Sans at build time and serves the woff2/woff
from this origin. Same first-party plugin, no extra dependency, one fewer third-party
request on every page load.

**Vite `server.host: 'localhost'`.** Vite binds `127.0.0.1` by default while `APP_URL` is
`http://localhost:8000`. Those are *different origins* to a browser even though both are
loopback, and module scripts are CORS-checked where classic scripts aren't — so the dev
server's asset tags get blocked with no obvious error. Pinning the host to match `APP_URL`
avoids it.

`npm run build` verified clean, and confirms all three entrypoints emit separately:

```
app-Bf1JEGcK.css   53.80 kB   ← Tailwind
app-BL5qjwUN.css    1.59 kB   ← SASS
app-BFvB23fQ.js    80.26 kB   ← jQuery
fonts-C9MNnjVw.css  2.35 kB   ← self-hosted Instrument Sans
```

---

## 2026-09-08 — Schema, models, routes, pages

**Migration/model plan.** The brief's initial scaffold is "the stack plus a landing page,"
but it also asks for migrations, models, factories, seeders, foreign keys and sensible
indexes — so the schema models the thing this app actually exists to do rather than being
decorative. Two tables:

| table | notable columns | keys and indexes |
|---|---|---|
| `projects` | `title`, unique `slug`, `summary`, `description`, `stack`, `repo_url`, `demo_url`, `is_featured`, `sort_order`, `published_at` | FK `user_id` → users `nullOnDelete`; index on `user_id`; composite `(sort_order, published_at)`; composite `(is_featured, published_at)` |
| `milestones` | `title`, `notes`, `sort_order`, `completed_at` | FK `project_id` → projects `cascadeOnDelete`; composite `(project_id, sort_order)` |

Three index decisions worth recording:

- **Postgres does not index foreign key columns automatically.** InnoDB does, which is where
  the habit of omitting them comes from. On Postgres an unindexed FK means a sequential scan
  on every parent delete and every `where project_id = ?`. Both FKs here get an index.
- **`milestones` needs only the composite.** `(project_id, sort_order)` covers `project_id`
  lookups on its own as the leftmost column, so a separate FK index would be dead weight.
- **`is_featured` gets no index of its own.** A two-value boolean is too low-cardinality for
  the planner to bother with; paired with `published_at` it matches the homepage query.

The delete behaviours are deliberately asymmetric: milestones cascade because they're
meaningless without their project, while `user_id` nulls out because the learning log should
outlive the account that made it.

```bash
php artisan make:model Project -mf --no-interaction
php artisan make:model Milestone -mf --no-interaction
php artisan make:seeder ProjectSeeder --no-interaction
php artisan make:controller HomeController --no-interaction
php artisan make:controller ProjectController --no-interaction
php artisan make:controller MilestoneController --no-interaction
php artisan make:request UpdateMilestoneRequest --no-interaction
```

Laravel 13's `User` model uses PHP attributes (`#[Fillable([...])]`, `#[Hidden([...])]`)
rather than `protected $fillable`, so `Project` and `Milestone` match that. Both follow
`eb-portfolio`'s member ordering (properties → accessors → scopes → relationships last, with
`// Scopes` / `// Relationships` headers), its `scopeOnly*` / `scopeNot*` filter-scope naming,
and its `Illuminate\`-imports-first ordering.

**Seeder content is real, not faker.** `ProjectSeeder` writes the six sub-projects actually
planned for this learning track, with genuine milestone lists — so a fresh `migrate --seed`
produces something worth looking at. It's `updateOrCreate` keyed on slug and rebuilds each
project's milestones, so re-seeding is idempotent and can't strand milestones from an earlier
version of the list. "Deployment Pipeline" is seeded with zero milestones on purpose, to
exercise the empty state.

**Routes.**

```
GET    /                                               home
GET    /projects                                       projects.index
GET    /projects/{project:slug}                        projects.show
PATCH  /projects/{project:slug}/milestones/{milestone}  projects.milestones.update  [auth]
```

Projects bind by slug declared in the route rather than via `getRouteKeyName()` on the model,
per `eb-portfolio`'s convention — the binding column stays visible at the URL that uses it.

Two consequences of that which the code has to handle explicitly:

- **Binding ignores the published scope.** `{project:slug}` resolves drafts and future-dated
  projects perfectly happily; the scope only guards the *listing* queries. `ProjectController::show()`
  therefore carries an `abort_unless($project->is_published, 404)`.
- **Scoped bindings don't apply.** Laravel auto-scopes a child to its parent only when the
  parent uses its default key. This route binds the parent by slug, so
  `MilestoneController::update()` checks `$milestone->project_id === $project->id` by hand —
  without it, any milestone id would resolve under any project's URL.

**The AJAX example is the milestone toggle**, and it's the real feature rather than a
throwaway demo. `MilestoneController::update()` answers JSON to `expectsJson()` requests and
a redirect otherwise, so the same route serves both paths. The checkboxes sit in real
`<form>`s with submit buttons; `app.js` deletes the buttons and takes over the `change`
event, meaning the no-JS path isn't hypothetical — it's the markup that ships, minus a page
reload. A hidden `is_complete=0` input precedes each checkbox because an unchecked box sends
nothing at all.

`Project::progressLabel()` deliberately reads the loaded `milestones` relation instead of
querying, so rendering a list of cards doesn't turn into N+1 selects; the two list queries
use `withCount()` for the same reason.

**Views.** `layouts/app.blade.php` is now the one shared site shell — public pages and
Breeze's authenticated pages both render through it, so header and footer are identical
everywhere. It keeps Breeze's `$header`/`$slot` slot contract so `dashboard` and `profile`
kept working untouched, and adds an optional `$title`. `layouts/navigation.blade.php` and
`welcome.blade.php` were deleted (grepped for references first).

Accessibility: skip-to-content link, `aria-current="page"` on the active nav item,
`aria-expanded` kept in sync on the nav toggle and dropdown trigger, `<label>` bound to every
milestone checkbox, a visually-hidden completion status for the guest read-only view, and a
`:focus-visible` outline applied globally in `_base.scss` (Tailwind's preflight removes the
UA default, so it has to be put back once rather than remembered per-component).

---

## 2026-09-08 — Tests, Pint, docs, verification

**Tests** run on in-memory SQLite (`phpunit.xml` already sets `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:`) — Laravel's default, independent of the app's Postgres driver.
`php8.3-sqlite3` was already installed on this machine from `eb-portfolio`'s setup, so unlike
that project there was no "could not find driver" detour.

Deleted the two `ExampleTest.php` stubs and added three files:

- `HomeTest.php` — the home page renders; it shows only published **and** featured projects
  (not drafts, not unfeatured, not future-dated); the index excludes drafts.
- `ProjectTest.php` — slug binding resolves; drafts and scheduled projects 404 (a dataset over
  both states); the `onlyPublished` scope; the `is_published` and `stack_items` accessors;
  milestone ordering; `progressLabel()` including its empty case; and both FK delete
  behaviours — cascade for milestones, null-out for the owner.
- `MilestoneTest.php` — the toggle route redirects guests to login; the AJAX path returns the
  recomputed progress; the plain-form path redirects back; a milestone belonging to another
  project 404s; validation rejects a non-boolean; and re-completing an already-complete
  milestone keeps the original timestamp.

Full suite: **42 passed, 96 assertions**.

**Pint.** Added a `pint.json` copied from `eb-portfolio` — `laravel` preset plus
`class_attributes_separation` (all four `elements` keys, since a partial map replaces rather
than merges the defaults) and `ordered_imports: {sort_algorithm: none}`. That second rule is
what allows the `Illuminate\`-first import convention: the preset's alphabetical sort would
put `App\` above `Illuminate\` and revert it on every run. The trade-off is that Pint no
longer fixes *or* flags a misordered `use` block, so it's on the author. `no_unused_imports`
still runs.

`vendor/bin/pint --format agent` found exactly one issue across everything written here (an
unused `Collection` import), which is a decent signal the hand-written style already matched.

**Version pinning.** Both manifests now pin exact versions with no `^`/`~` ranges, using the
actually-resolved versions. The `php` constraint stays `8.3.*` — an exact patch pin would
break `composer install` on any other 8.3.x, which isn't what "pin exact versions" means for
a language runtime. Ran `composer update --lock` (refreshes the lock's content-hash without
moving any package) and `npm install` afterwards so both lockfiles stay consistent with the
pinned manifests.

**`scripts/ensure-postgres.sh`**, lifted from `eb-portfolio`: checks `pg_isready` first and
skips `sudo` entirely in the common case, only calling `sudo service postgresql start` when
Postgres is actually down, then polling for up to 5s and failing loudly rather than letting
`artisan serve` fail later with a confusing connection error. Wired into `composer.json` as
the first entry of both `dev` and `setup` (as an array step, not a `pre-*-cmd` event hook —
sequential composition is unambiguous). `composer run setup` also gained `--seed`.

**`.gitignore`** got `/.claude/` and `*.code-workspace` on top of Laravel's defaults, matching
`eb-portfolio`. This is exactly why this project's setup log lives in `docs/` instead.

**`.env.example`** annotated and brought in line with `.env` — verified they differ only by
`APP_KEY` and the DB password.

**`CLAUDE.md`** got a "Project conventions" section appended *below* the
`</laravel-boost-guidelines>` closing tag, so `boost:update` can't wipe it. It points at this
log and restates the stack constraints (no Alpine, no `tailwind.config.js`) and the code-style
rules Pint can't enforce.

### Final verification

| check | result |
|---|---|
| `php artisan migrate:fresh --seed` | clean |
| `npm run build` | clean, 4 bundles |
| `php artisan test --compact` | 42 passed, 96 assertions |
| `vendor/bin/pint --test` | clean |
| `composer validate` | passes (only the expected "avoid exact version constraints" advisories, which are intentional) |
| `curl` over every route | `/` `/projects` `/projects/{slug}` `/login` `/register` → 200; `/dashboard` `/profile` → 302 to login; unknown slug → 404 |
| live AJAX toggle | authenticated `PATCH` returned `{"is_complete":true,"progress_label":"1 of 4 complete"}` and toggled back cleanly |
