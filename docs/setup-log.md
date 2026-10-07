# Setup Log — eb-laravel-vilt

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

---

## 2026-09-08 — CI pipeline, and a latent bug it exposed

Added `.github/workflows/ci.yml`. Worth noting that `eb-portfolio` has **no** CI workflow —
only `deploy.yml`, which rsyncs to the box on every push to `main` without running a single
test first. So this isn't mirrored from there; it's new, and it's arguably a gap worth
closing on that project too.

### The bug this turned up first

Before writing anything, checked whether the suite would even pass on a clean runner. It
would not:

```
42 tests, 31 passed, 11 failed
Illuminate\Foundation\ViteManifestNotFoundException:
  Vite manifest not found at: public/build/manifest.json
```

Every test that renders a view goes through `layouts/app.blade.php`, which calls
`@vite([...])`, which reads `public/build/manifest.json`. That file is **gitignored**, so it
exists only after someone runs `npm run build`. The suite had been passing locally purely
because assets had been built earlier in the session — on a fresh clone it fails 11 tests,
and the error points at Vite rather than at anything the developer just changed.

Fixed in `tests/Pest.php` with `->beforeEach(fn () => $this->withoutVite())` on the Feature
suite. Laravel's own mechanism for this; it stubs the directive out so tests exercise the
application rather than the state of the asset build. Verified both ways — 42 pass with
`public/build` present *and* with it moved aside.

That the assets genuinely compile is now the `assets` CI job's responsibility, which is the
right place for it.

### Workflow shape

Two jobs, deliberately independent so they run in parallel — the `assets` job needs no PHP,
no Composer and no database, so coupling them would only slow feedback and blur which half
broke.

**`php` — lint, test, migrate.** Composer install *with* dev dependencies (the opposite of a
deploy install — Pest and Pint both live in `require-dev`), then `composer validate`,
`pint --test`, `php artisan test`, and finally the Postgres steps.

**`assets` — build.** `npm ci` rather than `npm install`, because it installs strictly from
`package-lock.json` and fails outright if the lockfile and `package.json` disagree — exactly
the guarantee wanted given every version is pinned.

### Three decisions worth recording

**A real Postgres service, even though the tests use SQLite.** `phpunit.xml` runs the suite on
in-memory SQLite, which is fast but hides everything driver-specific — SQLite silently
tolerates index and foreign-key definitions Postgres rejects, and this schema leans on both.
The service container exists so `migrate --seed` runs against the database the app actually
uses. Step-level `env:` overrides the `.env` values because Laravel loads dotenv in immutable
mode, which never overwrites a variable already in the environment.

**A rollback step.** `migrate:rollback --force` followed by `migrate --force` is the only
thing that ever calls the migrations' `down()` methods. Without it they rot silently until
the one day someone needs them. Verified locally: rollback drops `milestones` before
`projects`, so the foreign key ordering is satisfied, and re-applying is clean.

**`composer validate` without `--strict`.** Confirmed the exit codes first: plain `validate`
exits 0 despite the exact-pin advisories, `--strict` exits 1. Since those pins are
intentional, `--strict` would make CI permanently red for a deliberate choice.

The manifest check at the end of the `assets` job guards the contract between
`vite.config.js` and the `@vite([...])` call in the layout: dropping an entrypoint from the
Vite config doesn't fail the build, it fails at *runtime*, on every page. Tested the negative
case too — a bogus entrypoint name is correctly reported as missing.

`cancel-in-progress: true`, unlike `eb-portfolio`'s deploy workflow, which sets it to false.
Cancelling a CI run is safe because it touches nothing outside the runner; cancelling a
half-finished deploy is not.

### Not yet running

There's no GitHub remote configured for this repo, so nothing has executed on real
infrastructure — the workflow is verified by simulating each step locally (Pint, tests,
`composer validate`, the full migrate/rollback/re-apply cycle on the local Postgres, and the
manifest check including its negative case), not by a green run. First push to GitHub will be
the real test. `workflow_dispatch` is included so it can be run manually from a branch before
`main` ever depends on it.

---

## 2026-09-08 — Removed the Projects/Milestones domain

**Reversal of the schema decision in the "Schema, models, routes, pages" entry above.**
Recorded as a new entry rather than by editing that one, per this log's own rule.

### What was wrong with it

The `projects` table was copied from `eb-portfolio` almost column-for-column — `title`,
`slug`, `summary`, `description`, `repo_url`, `is_featured`, `sort_order`, `published_at`.
Those columns encode a **portfolio-showcase** concept: things you publish, feature, and
display to a visitor. That's exactly what `eb-portfolio` is for, and recreating it here gave
this project a second, competing notion of a "project" that meant something different
(a learning sub-app) while looking identical in the schema.

Mirroring the sibling project's conventions was right for tooling, code style and CI shape.
Mirroring its *domain* was not, and the resemblance was close enough to be actively
misleading.

### What was removed

Fifteen files deleted outright: both models, both controllers, `UpdateMilestoneRequest`, both
factories, `ProjectSeeder`, both migrations, `resources/views/projects/`, `project-card`, and
the two feature test files. Another twelve edited: routes, `HomeController`, the home page,
header and footer nav, `app.js`, `DatabaseSeeder`, `HomeTest`, README and CLAUDE.md.

`users` is now the only table beyond Laravel's own `cache` and `jobs`. Sub-projects bring
their own migrations, named for whatever they actually model.

### What replaced the AJAX example

The milestone toggle had been the brief's required jQuery AJAX example, and it went with the
domain. Replaced by a **pipeline check** on the home page: a form that posts a message and
gets it back reversed, alongside the PHP and Laravel versions that handled it.

Deliberately trivial and deliberately stateless — it stores nothing. Its whole job is to
prove the front end is connected end to end: Blade markup → the jQuery bundle → the CSRF
header registered by `$.ajaxSetup` → `PipelineCheckRequest` validation → a JSON reply
rendered into the DOM without a reload, with the `422` branch surfacing validation errors in
place.

It keeps the same progressive-enhancement shape the milestone toggle had, which was the part
worth preserving: a real `<form>` that posts normally and re-renders server-side, which
`app.js` merely intercepts to skip the reload. `PipelineCheckController::store()` branches on
`expectsJson()` to serve both.

One markup detail worth recording: the error target is a plain `<p data-error>` rather than
Breeze's `<x-input-error>`. That component renders **nothing** when there are no messages, so
on first load there'd be no element for jQuery to write a 422 into. The hand-rolled element is
always in the DOM, carrying the server-rendered error on the no-JS path and acting as the
write target on the JS path.

Throttled at `20,1` — it's an unauthenticated endpoint, and although it writes nothing there's
no reason to let it be hammered.

### Verification

- `php artisan test --compact` → **33 passed, 84 assertions** (was 42; the 9 removed were the
  domain's own).
- New `PipelineCheckTest` covers both transports, the redirect-and-render path, validation
  (missing and oversized, as a dataset), and that no auth is required.
- Live-checked all three paths against a running server: AJAX success returned
  `{"received":"Hello from Blade","reversed":"edalB morf olleH",...}`; an empty message
  returned `422` with Laravel's error envelope; a plain form post 302'd home and rendered the
  reversed string in the markup.
- `/projects` now 404s; `/` `/login` `/register` 200; `/dashboard` `/profile` still 302.

A note was added to `CLAUDE.md` explaining why there's no domain model and warning against
reintroducing a generic `projects` table by mirroring `eb-portfolio` a second time — this is
exactly the kind of thing a future session would otherwise redo.

**Follow-up:** the first pass missed dead CSS — `_components.scss` still carried
`.milestone.is-complete` / `.milestone.is-saving` rules for markup that no longer exists.
Removed. Worth noting the failure mode: Tailwind v4 purges unused *utility* classes
automatically, but hand-written SASS is compiled verbatim, so orphaned rules in the custom
layer ship silently and only a grep finds them.

---

## 2026-09-08 — Renamed the project to `eb-laravel-vilt`

Matches the `eb-` prefix already used across this developer's work (`eb-portfolio`,
`eb-ssh-2026`, the `eb-*` entries in `~/secrets/`).

**Earlier entries in this log still say `laravel-vilt`, and that's deliberate** — they
describe what was actually run at the time, under the name the project had then. Rewriting
them would break this log's append-only rule and make the `laravel new` invocation in the
scaffold entry wrong. Only the H1 changed.

### What was renamed

| thing | from | to |
|---|---|---|
| directory | `~/projects/laravel-vilt` | `~/projects/eb-laravel-vilt` |
| Postgres database | `laravel_vilt` | `eb_laravel_vilt` |
| Postgres role | `laravel_vilt` | `eb_laravel_vilt` |
| DB password file | `~/secrets/laravel-vilt-local-db-password` | `~/secrets/eb-laravel-vilt-local-db-password` |
| seeded dev sign-in | `dev@laravel-vilt.test` | `dev@eb-laravel-vilt.test` |
| npm package name | `laravel-vilt` | `eb-laravel-vilt` |

Plus every reference in `.env`, `.env.example`, `.mcp.json`, `README.md`, the CI workflow's
Postgres service, and `DatabaseSeeder`.

### Three things worth knowing

**Renaming the Postgres role did not clear its password.** Postgres warns that renaming a
role wipes an MD5-encrypted password, because MD5 verifiers use the role name as the salt.
Checked first: `SHOW password_encryption` is `scram-sha-256` on this cluster and the stored
verifier starts with `SCRAM-SHA-256$`, which is salt-independent — so
`ALTER ROLE ... RENAME TO ...` was safe in place. Confirmed afterwards by connecting over TCP
with the existing password. Had it been MD5, the password would have needed resetting in the
same transaction.

Existing connections had to be dropped with `pg_terminate_backend` first — Postgres refuses
to rename a database that anything is connected to.

**`.mcp.json` hardcodes an absolute path** to `artisan` (`/home/ebasham/projects/.../artisan`,
invoked through `wsl.exe`). Moving the directory without updating it would have left Laravel
Boost's MCP server pointing at a path that no longer exists — failing at MCP startup rather
than anywhere obvious. This is the one file where a directory rename is a *functional* change
rather than a cosmetic one.

**`APP_NAME` was left as `"Laravel VILT"`.** It's the human-facing title rendered in the
header and `<title>`, not an identifier — and `eb-portfolio` follows the same split, with
`APP_NAME="Ethan Basham"` rather than its repo slug. `composer.json`'s `name` was likewise
left at Laravel's default `laravel/laravel`, matching `eb-portfolio`.

---

## 2026-09-08 — First sub-project: World of Tanks dashboard (Inertia + Vue)

The first sub-project, and the moment the scaffold's central premise gets tested: the base
site stays Blade + jQuery, and this mounts **Inertia + Vue as an island** under `/wot`.

### The stack boundary

Two Vite entrypoints that never load on the same page:

| entry | loaded by | contains |
|---|---|---|
| `resources/js/app.js` | `layouts/app.blade.php` | jQuery, 80 kB |
| `resources/js/wot/app.js` | `resources/views/wot.blade.php` | Vue + Inertia, 187 kB |

`HandleInertiaRequests` is applied to the `/wot` route group in `routes/web.php` rather than
globally, so the Blade half of the site never pays for Inertia's headers or asset-version
handshake. `Inertia::$rootView` is `wot`, not Breeze's `app`.

The upshot is that a visitor to the marketing pages downloads no Vue at all, and the
dashboard downloads no jQuery — verified by grepping the rendered HTML for each bundle.

### The API, and the thing that blocks everything

`WargamingClient` wraps the public API. Two properties of it drove the design:

**It answers HTTP 200 for application-level failures** and puts the real outcome in a
`status` field. A successful HTTP response therefore proves nothing, which is why every call
funnels through one private `send()` that unwraps the envelope and throws
`WargamingException`. Getting this wrong would mean silently treating an error body as data.

**`application_id` comes in two flavours and the difference is operational, not cosmetic:**

| type | IP check | rate limit |
|---|---|---|
| Server | request IP must match one of up to 5 registered addresses | 20 req/s |
| Standalone | none | 10 req/s per IP |

The supplied key is a **Server** application, so the first live call failed with
`407 INVALID_IP_ADDRESS` naming this machine's public IP. That is a Developer Room
configuration issue, not a code one, so `WargamingException::isInvalidIpAddress()` exists to
distinguish it and the error message spells out both fixes. `php artisan wot:ping` answers
"do the credentials work at all" without needing a linked account or a browser.

The IP was whitelisted mid-build, and everything below was then verified against live data.

### OpenID, and why the callback is the sensitive part

Wargaming's flow is not OAuth2 and has **no code-for-token exchange**: the API hands back a
login URL (`nofollow=1` returns it as data rather than redirecting), and after the player
authenticates, Wargaming redirects back with `account_id`, `nickname`, `access_token` and
`expires_at` as plain query parameters. The token arrives in the URL.

That puts all the weight on `AccountLinkController::callback()`, which checks `status` first
and then validates every field as required — a partial response would otherwise write a row
that looks linked but cannot authenticate. Tokens last two weeks, are stored with Laravel's
`encrypted` cast, and are revoked at Wargaming's end on disconnect (best-effort via
`rescue()`, so local state never depends on their uptime).

A rejected token is treated as recoverable: `forgetToken()` clears the credential and leaves
the link, because reconnecting is one click and re-entering the account id is not.

### Schema

| table | keys and indexes |
|---|---|
| `wot_accounts` | FK `user_id` **unique** + cascade; `account_id` unique; index on `access_token_expires_at` |
| `wot_vehicles` | `tank_id` as a non-incrementing primary key; composite `(tier, nation, type)` |

`user_id` is unique rather than merely indexed so a second link can't be created silently.
`wot_vehicles` uses Wargaming's own `tank_id` as the primary key — the rows are wholly owned
upstream, there is no local identity worth preserving, and it's the column `tanks/stats`
joins on.

The encyclopedia lives in a table rather than the cache store because it's ~1,000 rows that
change only on game patches, and *every* per-tank stat row must join against it to become
readable — a cache miss mid-render would mean re-fetching the whole encyclopedia. Refreshed
by `wot:sync-vehicles`, scheduled weekly.

### Two bugs worth recording

**Inertia's SSR default was silently inflating the HTTP fake count.** A test asserting that
dashboard data is cached (2 API calls across 2 page views) kept seeing 3. The third request
wasn't Wargaming at all — `inertia.ssr.enabled` defaults to `true`, so Inertia was POSTing to
its SSR server at `127.0.0.1:13714`, and `Http::fake()` intercepts *all* outbound HTTP, not
just the host under test. Disabling SSR (this sub-project has no use for it — it's a personal
dashboard behind auth) fixed the count. Worth remembering generally: `Http::assertSentCount`
counts every faked request the framework makes, including ones you didn't write.

**Pint appends imports but never sorts them.** `fully_qualified_strict_types` added
`use App\Models\WotAccount;` to the bottom of `DashboardController`'s use block and
`Illuminate\Http\Client\Response` to the bottom of `WargamingClient`'s, both breaking the
`Illuminate`-first, alphabetical-within-group convention. `pint --test` passed anyway, exactly
as `CLAUDE.md` warns — import order is unenforced here because `ordered_imports` is switched
off. Fixed by hand; worth an explicit check after any Pint run that touches imports.

### Verified against live data

`wot:sync-vehicles` pulled **1,028 vehicles** across 11 pages (the endpoint caps `limit` at
100, so the command pages until a short page comes back rather than assuming a count).

`AccountDashboard` was then run against a real public account: 711 battles, 49.79% win rate
(354/711 checks out), real vehicle names joined from the encyclopedia, and `private` correctly
`null` because no access token was sent. Timestamps decoded sensibly too — an account created
in 2011 whose last battle was in 2012.

Test suite: **62 passed, 193 assertions**, of which 29 cover this sub-project via
`Http::fake()` — so CI needs no API credentials.

### Not yet verified

The OpenID round trip itself. It needs a real browser session against Wargaming's login page,
which can't be driven from here — the redirect out, the callback parsing and the failure
branches are covered by tests, but the live handshake is untested. That's the first thing to
exercise manually.

---

## 2026-09-08 — Wargaming theme for the dashboard, and OpenID confirmed working

### OpenID works

The previous entry listed the OpenID round trip as the one thing untested, because it needs a
real browser session against Wargaming's login page. It has now been run: account
`AirsoftPro13` (1012068276) is linked, token valid for the full two weeks, and — the part
that matters — the **`private` block comes back populated** (credits, gold, free XP). That
only happens when the access token is sent *and accepted*, so it confirms the whole flow, not
just the redirect. 35,988 battles and 430 garage rows render.

### Theme

Restyled the `/wot` island to match developers.wargaming.net rather than inventing a dark
skin. The palette was **sampled from their actual stylesheet** (`static/1.16.2/css/index.css`)
instead of eyeballed:

| role | value | where it came from |
|---|---|---|
| page background | `#0a161f` | their `body` rule |
| panels | `rgba(7, 21, 30, 0.9)` | their panel fill — the colour this change was asked for |
| borders | `#212c33` | their dividers and input borders |
| sunken (inputs, table head) | `rgba(0, 0, 0, 0.2)` | their `.search_input` |
| headings | `#fff`, uppercase, condensed | their `h1..h6` rule |
| links / accent | `#ffaa00` → `#ffd200` on hover | their `a` and `a:hover` |
| body text / muted | `#ccc` / `#abb0b6` / `#767a7d` | their `body` and secondary text |
| good / bad | `#49c7a5` / `#cc4933` | their success and error colours |

Two details worth recording:

**`rgba(7, 21, 30, 0.9)` is translucent on purpose.** On Wargaming's site it floats over a
full-bleed photograph, which is what makes it read as a panel rather than a flat fill. Rather
than ship their artwork, `AppShell.vue` lays two very low-contrast radial washes over the base
colour — enough depth that the transparency does something, at no extra request.

**Their headings use "WarHelios",** a font that isn't ours to serve. The rules use the same
fallback chain they declare (`Arial Narrow`, Arial) plus the uppercase and letter-spacing,
since the narrow uppercase silhouette is what actually carries the look.

### How it stays out of the Blade site

The tokens live in the shared `@theme` block in `resources/css/app.css`, which sounds like
leakage but isn't: **Tailwind v4 emits a theme variable only when a scanned template uses it**,
and nothing outside `resources/js/wot/**` references them. Confirmed in the compiled CSS —
`--color-wot-blue` is absent from the build precisely because no component ended up using it,
while its sixteen siblings are present.

Rules that a utility class can't express (the page background behind the app root, native
`<select>` option colours, the focus-ring override) live in a **non-scoped `<style>` block in
`AppShell.vue`**. Not scoped, because scoped styles can't reach `body`; safe anyway, because
the file only ships in the World of Tanks Vite entry. The build confirms the isolation — a new
0.97 kB CSS chunk is attached to `resources/js/wot/app.js` in the manifest, and to nothing
else.

Verified by rendering both halves: `/wot` pulls the theme chunk and the Vue bundle, `/`
pulls neither.

### A debugging note

Grepping the served HTML for the hashed asset filenames found nothing at first, which looked
like the theme hadn't loaded. It had — `public/hot` existed because a Vite dev server was
running, so `@vite` was serving from `localhost:5173` and the manifest hashes weren't in the
markup at all. Check for `public/hot` before concluding an asset didn't build.

---

## 2026-09-09 — Matching tomato.gg: richer stats, WN8, and period tracking

Three phases, in answer to "what would it take to replicate tomato.gg's stats page".

### Phase 1 — the API was already giving us far more than we used

`account/info` returns **39** statistic fields and `tanks/stats` **33** per vehicle; the
dashboard was reading about eight. Added average tier, assist damage, blocked damage,
accuracy, damage ratio, K/D, max frags and max XP — no new dependency, no new table, just
reading the payload properly.

Marks of Excellence and mastery badges come from `tanks/achievements`, a **different endpoint**
that had to be added to the client. MoE is not in the statistics payload at all. Checked
against tomato.gg's own page for the same account:

| | ours | tomato.gg |
|---|---|---|
| MoE 1 / 2 / 3 | **104 / 8 / 5** | 104 / 8 / 5 |
| Mastery 3rd/2nd/1st/Ace | 34 / 87 / 135 / 144 | 33 / 87 / 134 / 144 |

Exact on MoE, one off on two mastery rows because their snapshot is slightly stale. Their
achievement data is this same public endpoint.

### Phase 2 — WN8

Expected values are **not** Wargaming data. XVM publishes them as one ~87 kB JSON covering 862
vehicles (`static.modxvm.com/wn8-data-exp/json/wn8exp.json`), refreshed weekly by
`wot:sync-expected-values`. That command bypasses `WargamingClient` deliberately — it's a
static CDN file with no application id, no envelope and no rate limit.

`Wn8Calculator` works on plain per-tank arrays rather than models, which is what lets the same
code serve both lifetime totals and period deltas: a "recent WN8" is this calculation over the
difference between two snapshots. Verified against the live account — **1547**, against
tomato.gg's WNX of 1553 (their own variant of the same idea).

The formula's constants are fixed by the community specification and are not tuning knobs.
Vehicles XVM has no values for are excluded and *reported* (`wn8_unrated_battles`) rather than
scored against a guess — 1,130 battles in this account's case, which the UI states plainly.

### Phase 3 — period statistics, and why they can't be backfilled

**The Wargaming API only ever returns lifetime totals.** There is no endpoint for "last 7
days". Every period figure on tomato.gg is a difference between two captures they took, which
means the only way to have that data is to start collecting it.

Two tables:

| table | holds | why |
|---|---|---|
| `wot_snapshots` | account lifetime totals per capture | the source of every period figure |
| `wot_vehicle_snapshots` | per-vehicle totals, **only for vehicles that changed** | recent WN8 is per-vehicle weighted, so account-level deltas can't produce it |

`wot:snapshot` runs hourly. It writes nothing when no battles have been played, and per
vehicle only when that vehicle's battle count moved — a player touches a handful of tanks out
of several hundred owned, so the table stays proportional to activity rather than to uptime.
Confirmed in practice: the first run wrote 431 vehicle rows (no prior state), the immediate
second run wrote none.

Statistics are stored as JSON rather than 39 columns. Nothing queries individual metrics —
deltas are computed in PHP across two rows — and Wargaming adding a field upstream shouldn't
require a migration. `battles` and `captured_at` are promoted to real indexed columns because
both period lookups filter on them: by date for 7d/30d/60d, by battle count for the "last
1000 battles" window.

**The design decision worth defending:** a period with no capture from before it began reports
`available: false`, not a number. Computing 30 days from whatever the oldest row happens to be
would present three days of play as a month's — plausible-looking and wrong. The UI says "not
enough history yet" instead. Right now every period says that, correctly, because history
began today.

### The bug this turned up

`PeriodStats` spreads the WN8 result into the period array, and `Wn8Calculator` was returning
a key named `battles` — which silently overwrote the period's own battle count with the WN8
*rated* count. Every other figure in the row was correct (win rate 60%, damage ratio 2.0, K/D
2.5), only `battles` read 0. A test caught it; a human reading the page probably would not
have, because nothing else looked wrong.

Fixed by removing the ambiguous key entirely: `rated_battles` and `unrated_battles` say what
they mean, and their sum is the total. General lesson — a function whose result gets spread
into someone else's array should not use generic key names.

Pint also re-appended an import out of order in `PeriodStats` (`WotVehicle` after
`WotVehicleSnapshot`), the third time this has happened. `pint --test` stays green, as
`CLAUDE.md` warns.

### Not replicated, and why

- **WNX** — tomato.gg's proprietary rating; the formula isn't published. WN8 is the standard
  equivalent and is computed exactly.
- **Per-battle detail** (damage/credits/accuracy for individual battles) — not in the public
  API at all. That comes from their own in-game mod uploading session data; matching it means
  shipping a mod.
- **Percentiles against server averages** — needs aggregate statistics across the player base,
  which they obtain by crawling.

Suite: **83 passed, 239 assertions.**

---

## 2026-09-09 — Grind tracker

### What the API does and doesn't expose

Established first, because it dictates the whole design:

| wanted | available? |
|---|---|
| Lifetime XP earned per vehicle | **yes** — `tanks/stats.all.xp`, already stored |
| Average XP per battle per vehicle | **yes** — `battle_avg_xp` |
| The research tree | **yes** — `encyclopedia/vehicles.next_tanks` and `.modules_tree`, each carrying `price_xp` |
| Account free XP | **yes** — private block |
| **Unspent XP banked on a vehicle** | **no** |
| **Which modules are already researched** | **no** |

The private block is `credits`, `gold`, `free_xp`, `bonds`, `battle_life_time`, premium and ban
status — checked directly against a live token. There is no per-vehicle XP pool and no elite
flag anywhere in the public API.

Coverage from the encyclopedia sync: 1,028 vehicles, of which 507 are premium (no research
line) and **362 have a `next_tanks` entry** — the whole tech tree. All 1,028 carry a
`modules_tree`.

### The consequence, and the design that follows from it

Because there is no unspent-XP figure to read, a grind cannot be *inferred* — it has to be
**declared**. `wot_grinds.baseline_xp` stores the vehicle's lifetime XP at the moment the
grind starts, and everything after is measured forward from that point.

This is stated on the page itself rather than hidden: anything earned before the grind was
added isn't counted, so a new grind reads pessimistically until its first unlock. A user who
doesn't know that would reasonably conclude the numbers are broken.

### The rate, which is the actually useful part

A vehicle's lifetime average XP is a poor predictor — it includes every battle since the
account was new, in a stock configuration, years ago. The snapshot history from the previous
entry gives something much better: XP actually earned in that vehicle over the last 14 days.

`GrindTracker` prefers the observed recent rate and falls back to the lifetime average,
**labelling which one it used** (`rate_source`). Day estimates are projected *only* from an
observed rate — a lifetime average says nothing about how often the vehicle is currently
played, so turning it into "days remaining" would be inventing information.

### Two bugs, both caught by tests, both quiet

**Counting a lifetime total as recent activity.** When no snapshot existed from before the
14-day window, the baseline fell through to zero — so `latest.xp - 0` treated the vehicle's
entire career as if it happened in a fortnight. A vehicle with 477k lifetime XP reported ~917
XP/battle instead of 500. Fixed by falling back to the *earliest capture on record* rather
than zero: a snapshot's `xp` is a lifetime total, so the only valid baseline is another
snapshot.

**A partial select breaking `Model::is()`.** The rate query selected
`['tank_id', 'captured_at', 'battles', 'statistics']` — no `id`. Every model therefore
hydrated with a null primary key, and `Model::is()` compares keys, so it reported two
completely different rows as the same one. The guard meant to skip vehicles with only a single
capture instead skipped *every* vehicle, and the whole feature silently fell back to lifetime
averages with no error anywhere. Worth remembering: **`is()` on partially-hydrated models
without the key returns true.**

### Verified live

The grinds page offers **303 owned vehicles** that have research targets. Declaring
M24 Chaffee → T37 produced: target 28,100 XP (the real cost from the tree), 0 earned (baseline
is current XP, as designed), ~71 battles at 397 XP/battle labelled `lifetime`, and
`days_remaining: null` — correctly, because one snapshot is not an interval. The test grind was
removed afterwards; the account is left as it was found.

Suite: **93 passed, 307 assertions.**

### A mistake worth recording

While debugging the period stats, a script run through `php artisan tinker` called
`WotAccount::factory()`. **Tinker runs against the development database**, not an in-memory
one, so it created a fake user and linked account in real data. It also caused a confusing
symptom: `WotAccount::first()` returned the fake row (Postgres does not guarantee order without
an `ORDER BY`), so a live API probe reported an expired token that was in fact fine.

Removed, and the real account verified intact. Factories belong in tests; tinker against a real
database should be read-only.

---

## 2026-09-09 — Scheduler running, snapshot history accruing

The grind rates and every period column depend on `wot:snapshot` actually running. Installed
the standard Laravel cron entry for the `ebasham` user:

```cron
* * * * * cd /home/ebasham/projects/eb-laravel-vilt && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>> .../storage/logs/scheduler.log
```

**Cron rather than `schedule:work`.** The foreground worker dies with its terminal, and this
history is the one thing in the project that cannot be recreated after the fact — a day the
scheduler wasn't running is a permanent hole in the record. cron survives terminal closes and
WSL restarts (`cron.service` is `enabled`, as is `postgresql`).

**Absolute PHP path and an explicit `cd`.** cron runs with a minimal environment and no
project working directory. Verified the exact command under `env -i` — a stripped environment
that mimics cron far better than an interactive shell does — before trusting it.

**stdout to `/dev/null`, stderr to a log.** `schedule:run` prints "No scheduled commands are
ready to run." every minute; logging that would be roughly 26 MB a year of noise. Errors
(Postgres down, a rejected token) still leave a trace instead of vanishing, which matters
because a silently broken scheduler looks identical to a quiet one until someone opens the
dashboard weeks later and finds empty columns.

Confirmed cron actually executed it — `journalctl -u cron` shows the command running as
`ebasham`, and `scheduler.log` is 0 bytes.

### What happens when the token expires

The access token has 13 days left. When it lapses, the snapshot run throws, `forgetToken()`
clears the credential, and the *next* run succeeds without one — `account/info` and
`tanks/stats` both work unauthenticated, they simply omit the `private` block. So the history
keeps accruing through an expired token; only credits/gold/free-XP go missing until the
account is reconnected. That degradation was not designed deliberately, but it is the right
behaviour and is worth keeping.

---

## 2026-09-09 — News & Events

### RSS, not scraping, for the article list

The news index HTML turned out to be a red herring. Buried in its inline JavaScript:

```js
URL_RSS_NEWS_INDEX = '/en/rss/news/',
URL_RSS_NEWS_CATEGORY = '/en/rss/news/-FAKESLUG-/'
```

worldoftanks.com publishes **official RSS feeds**, overall and per category, each carrying
title, link, description, `pubDate`, category and an image enclosure. That is an interface
meant to be consumed: it survives site redesigns, provides a stable guid per item, and removes
any question about whether parsing is welcome.

Categories are followed individually rather than via the overall feed, because the overall one
only carries the 20 most recent items across everything — quieter categories like
`live-streams` would be permanently crowded out by patch notes. Six feeds, 20 items each, 120
articles.

`robots.txt` permits `/en/news/` and disallows two subpaths (`wot-assistant`, `wgc-client`).
`NewsClient::mayFetch()` enforces that centrally rather than trusting each caller, and the
User-Agent identifies this application instead of impersonating a browser, so the traffic is
attributable and blockable if Wargaming ever objects.

### Event extraction: two tiers, and nothing inferred from prose

Sampling 19 real articles across every category first, rather than assuming:

| signal | coverage | yields |
|---|---|---|
| `event-calendar` component | **1 / 19** | exact per-session start/end times, titles, rewards |
| `data-timestamp` pair | **5 / 19** | one coarse overall window |
| JSON-LD `Article` | 19 / 19 | title and publish date (already in the feed) |

So roughly a third of articles produce a dated event, and only a small slice give per-day
granularity. **Prose dates are deliberately not parsed.** Plenty of articles say "the event
runs from 8 to 15 September" in a paragraph, and extracting that means brittle patterns or a
language model — a calendar that is silently *wrong* is worse than one that is merely sparse.

When an article has both a calendar and a timestamp pair, the calendar wins outright: emitting
both would draw a duplicate month-long bar behind every session.

### Parsing without a new dependency

The site emits attributes with no separating whitespace —
`data-accent="stream"data-date="2026-09-08"`. Verified up front that PHP's built-in
`DOMDocument` handles it (libxml warns, then parses correctly), so no HTML-parsing package was
added. The test fixture reproduces the run-together attributes exactly, because a parser that
quietly stopped coping would produce an *empty* calendar rather than an error.

Times come from `.local-date-ctw` / `.local-time-ctw` spans read positionally, and are UTC —
those spans are what the site's own JavaScript rewrites into the viewer's timezone, so the
served values are canonical.

### Being a polite client

Bodies are one request each against someone else's server, so: capped per run
(`bodies_per_sync`, default 15), 700 ms between requests, and re-fetched only when the feed's
`published_at` moves past `body_fetched_at`. A `body_hash` means an unchanged article is never
re-parsed. Scheduled twice daily, which is ample for a news site.

### A design problem the real data exposed

The first working calendar put every event in every day it spanned — correct, and unusable.
Battle Pass Season XXI runs 1 September to 24 November, so it filled **34 of 35 squares**,
burying the stream sessions that are the entire reason to look at a calendar.

Events spanning more than a week are now listed once above the grid as "running all month".
The grid went from 34 busy days to 16, and the sessions with real times (`16:00–22:59`) are
visible again. Worth noting the general lesson: this was only apparent with production data —
the fixtures all looked fine.

Suite: **107 passed, 344 assertions.**

---

## 2026-09-09 — Pinning articles

A pin is **per user**, in its own `wot_article_pins` table, rather than a boolean on
`wot_articles`. Articles are shared rows synced from Wargaming's feed and owned by nobody, so
a flag on the article would mean one person pinning something rearranged everybody else's
feed. The table also gives pins their own `pinned_at`, which is what orders several of them.

### Ordering

`scopePinnedFirstFor()` left-joins the pin table for the current user rather than using
`whereHas`, because the pin timestamp has to be available to `ORDER BY` — and a join keeps it
to one query that the paginator can still drive. `wot_articles.*` is selected explicitly since
both tables carry an `id`.

The sort is `pinned_at is null`, then `pinned_at` descending, then `published_at` descending.
Postgres orders `false` before `true`, so the first clause hoists pinned rows without needing
a `CASE`.

Pinned-first ordering composes with the category filter rather than overriding it: filtering
to Updates shows pinned *updates* first, not a pinned Special. A "Pinned" toggle gives the
cross-category view instead.

### A real bug the live test exposed

Pinning an article and reloading showed two same-day articles swapping places between
requests. They share a `published_at`, and with no further sort clause the database is free to
return tied rows in any order.

That is not cosmetic once the list is paginated: a non-deterministic sort across a paginated
set can put one article on two pages and another on none. Both ordering scopes now end with
`id` descending, and there's a test that pages through 30 articles sharing one timestamp and
asserts it sees 30 distinct ids.

Worth remembering generally — **any paginated query needs a unique final sort column**, and
the symptom (an item silently missing from a list) is one nobody reports as a bug.

### Interface details

The pin button sits *outside* the card's anchor. A `<button>` nested inside an `<a>` is
invalid markup and clicking it would follow the link as well; positioning it absolutely over
the card keeps both controls working. Requests use `preserveScroll`, so pinning something
halfway down the list doesn't throw the page back to the top — the reorder is visible without
losing your place.

Re-pinning is idempotent, via `syncWithoutDetaching`.

> **Correction (later that day):** this originally carried an extra
> `updateExistingPivot` call, on the belief that `syncWithoutDetaching` leaves an existing
> row's pivot alone. It does not — `InteractsWithPivotTable::attachNew()` calls
> `updateExistingPivot` itself for any id already present. The extra call was redundant and
> has been removed. The same wrong assumption then caused a real bug in the seen tracker; see
> that entry.

Suite: **117 passed, 422 assertions.**

### Follow-up: no flash message on pin

Dropped the "Pinned to the top of your feed" / "Unpinned" banners. The card restyles and jumps
to the top of the list, so a banner only restated what the list already showed — and a message
that adds nothing still costs the reader a glance. A test asserts the absence rather than
merely not asserting the presence, so the intent survives someone later "fixing" the missing
feedback.

The flash messages kept elsewhere are the ones where nothing else says what happened:
connecting an account reports *which* nickname was linked, and the grind actions confirm a
target that isn't otherwise restated.

### Follow-up: pinned state belongs on the button, not the card

The pinned card originally took a gold border. That border is also the hover affordance, so a
resting pinned card looked identical to a hovered unpinned one — the same visual saying two
different things, which makes the hover cue useless precisely where there are pinned articles
to scan past.

The border is now hover-only, and the pin button alone carries pinned state (gold when pinned,
plus `aria-pressed`). A general rule worth keeping: don't overload a hover treatment with
persistent state.

---

## 2026-09-09 — "Seen" tracking

Five approaches were compared before building, because the obvious one was wrong.

| approach | why not |
|---|---|
| Click-through | Articles deliberately skipped stay new forever, so the badge count only grows and stops meaning anything. |
| Hover dwell 3s | Mouse-only, and in a grid the pointer simply *rests* somewhere while you read — scrolling parks it over whatever card passes beneath. That's structural, not a tuning problem. |
| **Viewport dwell** | **Chosen.** Literal "seen", works with mouse, touch and keyboard alike. |
| **Mark all as seen** | **Chosen** alongside it, as the way to clear a backlog. |
| Visit watermark | One timestamp, no table, no client detection — genuinely tempting at ~5 new articles a week, but can't leave one article unseen to come back to. |

`IntersectionObserver` at 60% visibility for 1.5s. Both numbers matter: the threshold stops a
card clipped at the viewport edge counting, and the dwell stops a fling from top to bottom
marking everything — the failure people actually resent. Marks are batched and flushed every
two seconds, so scrolling a full page is one request rather than twenty-four, and the flush
also runs on unmount so navigating away mid-interval loses nothing.

**Badges deliberately do not clear mid-scroll.** The flush asks only for `unseenCount`, so the
counter ticks down while the cards stay put — restyling them as they pass would make the grid
shimmer under the cursor. They clear on the next load, which is when you'd look again. The
`articles` prop became a closure so those partial reloads skip the paginator query entirely.

Absent `IntersectionObserver` the tracker does nothing and "mark all as seen" still works, so
it degrades rather than breaks.

### The bug, and the earlier mistake it exposed

A test asserted that re-seeing an article keeps its original `seen_at`. It failed: the
timestamp moved by an hour.

`syncWithoutDetaching` **does** update pivot attributes on rows that already exist —
`InteractsWithPivotTable::attachNew()` calls `updateExistingPivot` for any id already present.
Confirmed in the framework source rather than inferred. Every card scrolling past a second
time would therefore have rewritten its "first seen" time, so the column would have quietly
meant "last seen" instead.

Fixed by filtering already-seen ids and `attach()`ing only the rest.

The same wrong assumption had produced the opposite mistake in the pinning work earlier: an
explicit `updateExistingPivot` was added there *because* `syncWithoutDetaching` supposedly
didn't update. It was redundant all along. Removed, and the earlier log entry corrected in
place with a note rather than silently edited.

Suite: **132 passed, 497 assertions.**

---

## 2026-09-09 — Removed the rest of the redundant flash messages

Applied the rule properly this time instead of one banner at a time. A success flash earns its
place only when nothing else on the page reports the outcome.

Removed:

| action | what already says it |
|---|---|
| Mark all as seen | every NEW badge and the button itself disappear |
| Start tracking a grind | the grind appears in the list below |
| Remove a grind | the row vanishes |
| Disconnect account | the dashboard is replaced by the Connect screen |

Kept exactly one: **"Connected as {nickname}"**. It survives because it reports *which*
account got linked — a fact the page doesn't otherwise state — and it lands after a redirect
back from Wargaming's site, where some confirmation that the round trip worked is genuinely
useful.

Error flashes all stay. A failure is never self-evident from the interface.

Tests assert the absence rather than merely omitting the assertion, so the intent survives
someone later "restoring" what looks like missing feedback.

---

## 2026-09-09 — First push, and the two bugs only CI could find

Pushed to `https://github.com/EthanBasham/eb-laravel-vilt.git` over HTTPS (the `gh` CLI is
authenticated with `repo` and `workflow` scopes; SSH is not set up for GitHub on this machine
— `ssh -T git@github.com` returns `Permission denied (publickey)`). Verified the remote was
empty with `git ls-remote` before pushing, so no history had to be reconciled.

The first run failed twice, and both failures were the kind that **cannot** be found on the
machine that wrote the code.

### 1. `tests/Unit` didn't exist in a fresh checkout

```
Test directory ".../tests/Unit" not found.
```

`phpunit.xml` declares a Unit testsuite pointing at `tests/Unit`, but the directory had been
empty since the example test was deleted with the Projects/Milestones domain — and **git does
not track empty directories.** So it existed here and in no clone anywhere. A `.gitkeep` fixes
it, carrying an explanation so nobody deletes it as clutter.

Verified with `git worktree add --detach` from HEAD, which gives a genuinely clean checkout
without touching the working copy — a better check than trusting `git status`.

### 2. The suite depended on a personal credential

Fourteen tests failed on CI and passed locally. Every one of them fakes the Wargaming API.

`config/wargaming.php` reads `WARGAMING_APPLICATION_ID` from the environment, and
`WargamingClient` throws "No Wargaming application ID is configured" before issuing a request.
Locally `.env` holds the real key, so the client got past that guard and `Http::fake()`
intercepted. On CI, `.env` is a copy of `.env.example` where the key is deliberately blank —
so the guard fired first and the fake never ran.

**The suite had been green only because of a credential on one machine.** Pinned a dummy value
in `phpunit.xml` instead, which is the correct layer: a test should behave identically for
everyone, and none of these should ever reach the real API.

Confirmed the mechanism rather than assuming it — a scratch test dumping `config()`, `getenv()`
and `$_ENV` showed PHPUnit's `<env>` beating even a populated `.env`, which is what makes the
suite deterministic.

The general lesson, and the reason the CI work earlier was worth doing: a test suite that has
only ever run on its author's machine is untested itself. Both of these were invisible until
the code ran somewhere it had never run.

---

## 2026-09-09 — Production deploy at laravel-vilt.ethanbasham.xyz

Live. The app rides the shared infrastructure documented in
`~/projects/eb-portfolio/docs/new-portfolio-project-site-setup.md`; this entry only records
what was incremental.

**Most of it already existed.** A previous infrastructure pass had provisioned this app's slot:
DNS `A` record to `54.211.52.97`, an nginx vhost with a Let's Encrypt cert, the
`/projects/laravel-vilt/{releases,shared,current,.trigger}` layout, and an active
`deploy-apply@laravel-vilt.path` systemd watcher. `deploy-apply.sh` even already named
`laravel-vilt` in both of its case statements. Checking first turned a nine-step runbook into
six small gaps.

**Steps 1–5 of the runbook were skipped entirely** — S3 prefixes, a per-app IAM user, a dev
ACM cert and two CloudFront distributions all exist to serve user-uploaded files. This app
stores none, so none were created. No new AWS spend.

### What was actually done

| gap | resolution |
|---|---|
| vhost was a static placeholder | rewritten as a Laravel vhost: root on the `current` symlink, `try_files … /index.php`, fastcgi to a per-app socket, `fastcgi_param HTTPS on` so PHP generates `https://` URLs behind the TLS terminator. Certbot's managed lines reproduced verbatim so renewal still recognises the file. |
| no php-fpm pool | `/etc/php-fpm.d/laravel-vilt.conf`, mirroring eb-portfolio's — own socket so one app can't starve another, `pm=ondemand` so an idle app costs nothing. |
| `shared/` empty | `.env` (0640, `nginx:developers`) and `storage/`, symlinked into each release by `deploy-apply.sh`. |
| no database | `eb_laravel_vilt` + scoped role `eb_laravel_vilt_app` on the shared RDS. |
| no pipeline | `.github/workflows/deploy.yml`, adapted from eb-portfolio's. |
| no repo secrets | `DEPLOY_SSH_KEY`, `DEPLOY_HOST`, `DEPLOY_SG_ID`, and the AWS CI pair. |

### Three things that went wrong, and what they taught

**RDS is not publicly accessible.** The first attempt ran `psql` from this laptop and hung.
The instance is VPC-only (private IP `172.31.13.10`), so all database administration has to
run from the box. Correct by design; worth knowing before debugging a timeout.

**The master user is `eb_admin`, not `postgres`.** Assumed rather than checked, which cost a
round trip. `aws rds describe-db-instances --query 'DBInstances[0].MasterUsername'` answers it.

**`CREATE DATABASE … OWNER` failed with `must be able to SET ROLE`.** Exactly the wall
`eb-portfolio` hit and documented: on RDS the master user is not a superuser, and PG16+ requires
the creator to be a member of the owning role. `GRANT eb_laravel_vilt_app TO eb_admin` first,
then create. Reading the sibling project's log was faster than rediscovering it.

Also a self-inflicted one: a heredoc on the `ssh` invocation silently overrode the pipe feeding
credentials to its stdin, so the remote script read the script text as its password. Passing
secrets over SSH's stdin works — but not while a heredoc is also claiming stdin.

### Scheduling

**There is no cron on this box.** Amazon Linux 2023 ships without cronie, and `eb-portfolio`
runs no scheduler at all, so there was no precedent to copy. Used a systemd timer instead,
templated on the app name (`laravel-scheduler@laravel-vilt.timer`) to match the existing
`deploy-apply@<app>` units, so the other Laravel apps can enable it later without new files.

`StandardOutput=null` because `schedule:run` prints a line every minute when nothing is due;
`StandardError=journal` keeps real failures visible. `Persistent=false` — replaying missed
ticks after downtime would run nothing useful, since the scheduler decides what is due from the
clock.

The local crontab on the development machine was **removed** in the same change. Two schedulers
writing snapshots to two different databases would have produced two incomplete histories and
burned twice the API quota.

### Verified

`https://laravel-vilt.ethanbasham.xyz` returns 200 with the real app; `/login` and `/register`
200; `/wot` correctly 302s to login; assets served from the release's `build/` directory.
Production data: 1,028 vehicles, 862 WN8 expected values, 120 articles, 17 events.

**The Wargaming API works from the box** — `wot:ping` returned live results, so `54.211.52.97`
is already on the application's allow-list. This had been flagged as a likely blocker before
deploying, on the grounds that the key is a Server-type application restricted to the home IP.
Checking cost one command and saved raising a false alarm.

---

## 2026-09-09 — Removed the grind tracker

Built, used, didn't fit how this player actually plays. Stripped rather than left switched
off — a feature nobody opens still costs a nav slot, a scheduled query, and attention every
time someone reads the code.

Deleted outright: `WotGrind`, `GrindController`, `StoreGrindRequest`, `GrindTracker`,
`WotGrindFactory`, `Grinds.vue`, `GrindTest`. Edited: routes, the `grinds()` relation on
`WotAccount`, and the nav link.

### Things that only existed to serve it

Worth removing carefully, because they didn't look grind-specific:

- **`AccountDashboard::vehicleStatsFor()`** had been made *public* specifically so
  `GrindTracker` could share the dashboard's cached tanks/stats. With the tracker gone nothing
  called it at all — `vehicles()` does its own cached fetch — so it was deleted rather than
  quietly demoted back to private.
- **`wot_vehicles.next_tanks`, `.modules_tree`, `.is_gift`** were added to resolve grind
  targets and nothing else ever read them. Dropped, along with the extra fields the
  encyclopedia sync was requesting. `modules_tree` alone was several megabytes of JSON across
  ~1,000 rows, re-fetched on every sync.
- **`WotVehicle::scopeOnlyResearchable()`** had no remaining callers.

The create migrations stay in history — they ran in production — and a new migration drops
what they made. Its `down()` restores the columns but not their contents, and says so: the
sync's field list would have to be widened again to refill them.

Suite: **123 passed, 430 assertions** (down from 133; the ten removed were the grind tests).

---

## 2026-09-09 — Dashboard load time

Reported as slow. Measured before changing anything, which mattered: the database was never
the problem.

| | before | after |
|---|---|---|
| cold (cache expired) | **9.6 s** | **1.2 s** service / 2.4 s full page |
| warm | 114 ms | 64 ms service / 0.33 s full page |
| DB time | 34 ms | 17 ms |

Three causes, in order of size.

**The API was returning 1.8 MB of data to render a few hundred numbers.** `tanks/stats`
defaults to 33 fields per vehicle; the page reads about fifteen. Passing an explicit `fields`
list cut the response from **1,857,292 to 140,986 bytes** and 2.66 s to 1.37 s — and the
transfer is the smaller half of that win. The payload is `json_decode`d *and* re-serialised
into the cache, and `CACHE_STORE=database`, so every cold load was writing ~1.8 MB into a
Postgres row. `tanks/achievements` got the same treatment (288 KB → 121 KB) by dropping the
`series` blocks nothing reads; it rejects nested field paths, so only the top-level trim is
available there.

That single change took the cold load from 9.6 s to 3.2 s.

**Three independent calls were made in sequence**, so a cold load cost their sum rather than
the slowest one. `Http::pool` fires them together. A pool hands back the exception object
instead of throwing, so a connection failure has to be re-raised deliberately — otherwise it
would surface much later as a missing array key.

**Three cache entries where one would do.** They are always wanted together, so they are now a
single `wot:payloads:{account}` entry: one database write per cold load instead of three.

### The TTL, and why a Refresh button came with it

The cache was 5 minutes, so the first visitor in any 5-minute window paid the full cold cost.
Raised to 30 minutes — stats only move when a battle ends — but a longer window is only
defensible if staleness is escapable, so the dashboard gained a **Refresh** control that drops
the entry and re-fetches. Without that the change would have traded one annoyance for another.

`config/wargaming.php` now has one `cache.dashboard` key rather than the two that described
the old per-endpoint entries.

### A note on the field list

`WargamingClient::TANK_STATS_FIELDS` has to stay a superset of two consumers: what the
dashboard renders per vehicle, and what `PeriodStats` differences between snapshots. Removing
a field there breaks one of them **silently, as a zero rather than an error**, which is why the
constant carries that warning.

---

## 2026-09-09 — News and upcoming-events panels on the dashboard

Two columns above the statistics: a condensed news list with Latest/Pinned tabs, and the next
five days of events.

**Both read local tables only.** No API call, a few milliseconds, and — deliberately — they
still render when the Wargaming call below them fails. The error path returns them alongside
the error, so an outage costs the numbers rather than the whole page. There's a test for that
specifically, since it's the kind of thing that silently regresses.

### Decisions worth recording

**Both tabs ship with the page.** Five rows each is a trivial payload, and a tab that costs a
round trip feels broken. The tab state is a client-side `ref`.

**Long campaigns are summarised, not repeated.** Checking the real data first was what shaped
this: over the next five days there are six events, but three run 14, 29 and 83 days. Putting
them in every day box would have printed fifteen rows to bury the three that are actually
scheduled. Anything spanning more than a week drops to an "Also running" footer — the same
threshold and reasoning as the month calendar, so the two read consistently.

The seven-day cutoff earns its place in the live data: "Trade In and Roll Out" runs exactly
seven days and correctly stays in the day boxes, while the 14-day campaign moves to the
footer.

**Times appear only on the day a session starts.** A multi-day window rendered with "16:00" on
each of its days would be stating something untrue.

**Fixed thumbnail box.** The feed's images vary in size and 11 of 120 articles have none; a
fixed `h-12 w-20` with `object-cover` keeps every row the same height. Titles are
`line-clamp-2` for the same reason — headlines vary enough that a ragged list is harder to
scan. `line-clamp` is core in Tailwind v4, so no plugin was needed.

Unseen articles carry a small green dot plus visually-hidden "(unread)" text, reusing the
existing seen tracker rather than inventing a second notion of new.

Suite: **130 passed, 499 assertions.**

---

## 2026-09-09 — Pinning from the dashboard panel

The pin routes already existed and already used `back()`, so they worked unchanged from the
dashboard. The work was the panel's own state and one bug.

**The Latest tab now hoists pinned articles.** Without that, pinning something from the
dashboard produced no visible change until you switched tabs — the control would have looked
broken.

**Pinning reloads only the `news` prop.** A full Inertia visit would resend several hundred
vehicles of garage JSON for a change that touched none of it. Both tabs come back together, so
the Pinned list stays correct without a second request.

### The bug, which was mine and recent

Adding `is_pinned` meant chaining `withSeenFor()` and `pinnedFirstFor()` on the same query, and
the seen state silently vanished: `is_seen` came back `false` for an article that had been
marked seen.

`pinnedFirstFor()` called `->select('wot_articles.*')`, and **`select()` resets the column
list** — discarding the correlated subquery `withSeenFor()` had already added. Chained the
other way it worked; chained this way it didn't, with no error either way.

The irony is that `withSeenFor()`'s own docblock had described this hazard and claimed a
subquery avoided it. It avoids *duplicate rows*, not a reset `select()`. Both scopes now use
`addSelect`, so they compose in either order — verified explicitly by running the chain both
ways and checking both columns come back populated.

A test caught it, and the test that caught it was one written for a different feature entirely
(the unseen marker), which is a fair argument for asserting the boring things.

Suite: **131 passed, 527 assertions.**

---

## 2026-09-09 — Grinding board, rebuilt from the spreadsheet

`docs/WOT Stat Trackers.xlsx` has seventeen sheets; five of them are the grind planner. They
turned out to be **one dataset viewed five ways**, not five datasets, which is what the schema
models: a target vehicle, an ordered path of steps, and every manual number living on a step.

### Decoding the sheets

No xlsx library was installed and none was added — a workbook is a zip of XML, so a ~40-line
parser read it directly.

| sheet | what it is |
|---|---|
| Active Grinding | tanks being played, with **XP banked on them** — the one figure the Wargaming API cannot report, and the reason this board is manual |
| XP Remaining | the path to each target: pairs of *(modules at tier N, cost of the tier N+1 unlock)*, ending with a Tier X modules column |
| Free XP Usage | Free XP earmarked per tier |
| Tanks to Purchase | credits to buy each vehicle on the path |
| Blueprints | fragments held per tier |

The column pairing was confirmed arithmetically on Concept No. 5:
`75,400 + 109,062 + 124,400 + 225,000 = 533,862`, the sheet's own total.

**Blueprints column J is the API's undiscounted research cost** — identical on twelve of
eighteen rows, the other six only failing my name matching. That explained the whole workbook:
the XP Remaining figures are *post-blueprint*. IS-4 is 189,000 in the API and 149,310 in the
sheet, exactly ×0.79.

### Where the numbers come from

Confirmed against the API before designing: `price_credit` matches the sheet exactly
(6,100,000 per tier X), and the reconstructed tech-tree path for Concept No. 5 gives
3,400,000 + 6,100,000 = **9,500,000**, the sheet's own credit total.

So the API supplies the *skeleton* — path, full research costs, credit prices — and the sheet
supplies what only a player knows: banked XP, the discounted figure, module XP, Free XP intent,
fragments.

Blueprint discounts are **entered, not computed**. Wargaming doesn't publish the
fragments-to-discount curve, so deriving it would mean reverse-engineering a formula that rots
silently on the next rebalance. The game already shows the real number.

### Two things the import got wrong first

Both surfaced by reconciling totals against the sheet rather than eyeballing the output.

**The auto-path walked back too far.** "Owned" is inferred from snapshot history, which records
what has been *played*; the sheet knows what has been *researched*. Researched-but-unplayed
vehicles made paths include tiers long finished, inflating the total by ~600k XP. The import
now trims each path to the tiers the sheet actually covers — where the sheet has an opinion it
wins, because it is the record of real progress.

**A tier with module XP but no research figure is already researched.** Leaving that null fell
back to the API's full price and re-charged for unlocks paid for years ago — K-91 read 562,000
instead of 158,800. Zero, not null.

After both fixes the board reconciles **exactly**: 6,073,283 XP required, 1,001,318 banked,
358,500 Free XP planned — every figure matching the spreadsheet, and every per-target total
matching its row. Progress percentages agree to the decimal (ST-I 55.8% against the sheet's
0.5579).

### Name matching

41 of 46 names resolved automatically; three of the failures were label rows ("Total",
"Vacant Slots"). The two real ones needed aliases: **Błyskawica** (a Polish `Ł` the normaliser
couldn't fold) and **Tesak**, an in-game nickname that isn't in the encyclopedia at all — it is
Object 452K.

### The page

Five tabs over one board, plus inline editing: every manual number is an input that saves on
blur and reloads only the board props, never the rest of the page.

`wot:import-grind-sheet` is idempotent and reads a JSON fixture extracted from the workbook, so
no spreadsheet-reading dependency ships for a one-off import.

Suite: **147 passed, 592 assertions.**

---

## 2026-09-09 — Editable cells are plain text, not number inputs

The spinner arrows read as noise on a dense table, and arrow keys silently
nudging a figure is the wrong affordance for numbers transcribed from the game
rather than adjusted by feel.

`type="text"` with `inputmode="numeric"`, so touch devices still get the numeric
keypad. Non-digits are stripped on input rather than validated on submit, so a
stray character never sits in the field looking accepted. Values are grouped with
separators while idle and bare while editing — seven-figure numbers are hard to
read unseparated, but separators in a field you're typing into fight the cursor.

Applied to the shared component rather than only the Active Grinding table: the
same cells appear in every expanded step row, and leaving those as steppers would
have made one table behave unlike the rest.

The two planning inputs (credits available, vacant slots) are still `type="number"`
— a different form, filled in rarely, where the stepper does no harm.

---

## 2026-09-09 — Module upgrades as toggles, and banked XP that follows the game

"XP to Max" stops being a number to maintain and becomes a consequence of which module upgrades
are ticked. Ticking one drops banked XP by exactly its cost, because that is what researching a
module does in game.

### The API had it, and the spreadsheet proved it

`modules_tree` carries `module_id`, `name`, `type`, `price_xp`, `price_credit` and `is_default`
per vehicle. Checked before building: **the sheet's "XP to Max" is exactly the sum of a
vehicle's unresearched upgrade costs**, matching on 19 of 20 steps.

The twentieth was the tell. Object 705 read 79,300 against an API total of 140,300 — a gap of
61,000, which is precisely its 130 mm S-70 gun. Not a discrepancy: a module already researched.
That single row confirmed the whole model.

### A modelling error worth recording

The first schema keyed modules on `module_id` alone. The sync failed immediately with
`ON CONFLICT DO UPDATE command cannot affect row a second time` — **the same module fits many
vehicles**, so the encyclopedia repeats an id under each. The real identity is the
`(tank_id, module_id)` pair. Corrected in place rather than patched around, since the migration
hadn't shipped.

### Inferring the seed state

The sheet records a total, not a list, so which modules are done has to be inferred: zero means
all of them, the full sum means none, and anything between is solved as an exact subset. Brute
force over every combination, which is safe because a vehicle has at most a handful of upgrades
— and only exact matches are accepted. A near-miss is left alone with a warning rather than
guessed at, because a wrongly-ticked module would quietly corrupt the banked XP arithmetic from
then on.

Seeding deliberately does **not** touch banked XP: the sheet's figure is already what remained
after those modules were researched. Only a user's tick moves it.

### Behaviour

Ticking subtracts the module's cost from banked XP; un-ticking gives it back, so a misclick
costs nothing. Verified end to end on ST-I: 83,305 → un-tick the gun → 142,605 → re-tick →
83,305, with "to max" moving 0 → 59,300 → 0 alongside.

Banked XP floors at zero — a module can legitimately be researched with Free XP, leaving less
banked than it cost, and a negative balance would be nonsense on the page.

Where the encyclopedia has no modules for a vehicle the old manual field still shows, so a
vehicle it hasn't described doesn't silently read as fully upgraded.

Suite: **155 passed, 610 assertions.**

---

## 2026-09-09 — Vehicle lists follow the tech-tree nation order

USA, Germany, USSR, UK, France, Czech, Japan, China, Poland, Sweden, Italy — the game's own
order, which is neither alphabetical nor by vehicle count, so it has to be stated rather than
derived. All eleven slugs matched the encyclopedia's exactly.

Applied to every vehicle list on the board: Active Grinding, the target table behind all four
remaining tabs, and the add-target dropdown. Within a nation the sort is tier descending then
name, which is how a garage is scanned.

**Sorted in PHP, not SQL.** Postgres has `array_position` and SQLite — which the test suite
runs on — does not, so a query-level sort would have meant either a CASE ladder repeated in
every query or a suite that tests something different from production. The lists are at most a
couple of hundred rows.

The order lives in `config/wargaming.php` rather than inside a comparator, and an unrecognised
nation ranks *last*: a nation added by a future patch should appear after the known ones, not
silently displace them. There's a test for that, because it is the kind of default that is
easy to get backwards and never notice.

Completed targets still sink below everything — they are no longer part of the working list —
with nation order applied above them.

Suite: **158 passed, 640 assertions.**

## Nation flags replace nation slugs

Vehicle lists showed the API's raw nation slug (`ussr`, `czech`). Replaced with
the small in-game flag icons, matching how the game and tomato.gg's filters
present nations.

The API does not serve them. `encyclopedia/info.vehicle_nations` returns display
names only (`{"usa": "U.S.A.", "czech": "Czechoslovakia", ...}`) with no image
URLs. The icons live on Wargaming's static CDN instead:

    https://na-wotp.wgcdn.co/static/6.16.0_bbf399/wotp_static/img/core/frontend/
      scss/common/components/widgets/content-tank/img/{nation}.png

All eleven return 200 — 29x18 palette PNGs, ~1.4 KB each. **Self-hosted under
`public/images/nations/` rather than hot-linked:** that path carries a build
hash (`6.16.0_bbf399`) that will 404 on Wargaming's next static deploy. 44 KB
total, and `.gitignore` only excludes `/public/build`, `/public/hot` and
`/public/storage`, so the directory is tracked.

### Germany is not the CDN icon

Wargaming's German icon is the Nazi-era war flag — red field, white disc, black
swastika. Confirmed by decoding the PNG and rendering it upscaled, after the
palette's black + dark-red mix made it ambiguous at native size. It is not
committed here; a public repo is a different distribution context from an
in-game asset, and the symbol is illegal to display in several jurisdictions.

`public/images/nations/germany.png` is a generated modern Bundesflagge
(black-red-gold). To keep it from looking pasted-in next to ten waving-fabric
icons, the shading was lifted from `poland.png` — also two horizontal bands —
by normalising each pixel's luminance against its own band's mean and applying
that multiplier to the tricolour, with a small additive lift so folds stay
visible in the black band. The generator is not kept in the repo; the asset is.

USSR and the Kingdom-of-Italy naval ensign are period flags too, but carry
nothing prohibited, so those ship as-is.

### Wiring

- `config/wargaming.php`: `nation_order` (a list) became `nations`, an ordered
  slug => display-name map. Keys are the tech-tree order *and* the flag
  filenames; values are the API's own names, used as `alt` text.
  `WotVehicle::rankOf()` now flips `array_keys()` of it.
- `HandleInertiaRequests` shares the map as a `nations` prop. It is static, but
  sharing beats duplicating the list in JS — config stays the single source of
  truth for filenames and alt text both.
- `NationFlag.vue` renders the `<img>`, falling back to the slug as text for any
  nation a future patch adds before its flag exists.
- Replaced the slug in Grinding's Active and target tables and the dashboard
  garage row. The flag leads the name column rather than trailing it: at a fixed
  21px it forms a scannable left gutter, which a trailing flag can't do since it
  floats to wherever each name happens to end. In the expandable target rows it
  sits after the caret, which stays leftmost as the row's own control. The dashboard's nation `<select>` can't hold an image, so it shows
  display names instead — and now orders its options by the same tech-tree
  order rather than alphabetically by slug.

## Total row on Active Grinding

The Active Grinding table now carries a `<tfoot>` summing XP banked, To max, To
next tank and Remaining, plus an aggregate progress bar. Hidden when there is
one row or none — a total identical to the only row above it is noise.

Two decisions worth recording:

**Nested under `totals`, not a sibling prop.** Three places issue partial
reloads on this page (`EditableNumber`, `ModulePicker`, and the active checkbox
in the target rows), all with `only: ['active', 'targets', 'totals']`. A new
top-level `active_totals` key would have gone stale after every edit until
someone remembered to add it to all three lists. As `totals.active` it refreshes
with what already ships.

**Progress is weighted, not averaged.** Recomputed from the summed required and
covered XP rather than averaging the per-row percentages, so a finished 4,000 XP
module grind can't pull the figure as hard as a 400,000 XP tier 10. Test covers
the divergent case: rows at 100% and 0% read 1%, not 50%.

`GrindBoard::active()` had the filter-and-sort inline; that moved to
`activeSteps()` so the table and its total row sum the same collection by
construction rather than by two matching predicates.

Suite: **161 passed, 680 assertions.**

## Tanks to Purchase rebuilt as a tier matrix

The view was a row per line with a Credits column and a "Tanks to buy" count,
sharing a table with XP Remaining, Free XP and Blueprints. Rebuilt as its own
section: one row per research line, one column per tier, one cell per vehicle,
and no XP, module or banked figure anywhere on it.

Cell states, per the brief: a greyed `0` for a vehicle already bought, the price
in white with an **Unlock** button when it is not researched, the price in green
with a **Buy** button when it is researched but not bought. The greyed `0` is
itself a button that reverts — a mis-click would otherwise be permanent. A line
drops out of the table once its last vehicle is bought.

### Ownership state lives on the tank, not the step

New `wot_tank_purchases` table keyed on `(wot_account_id, tank_id)`, holding
`is_unlocked`, `is_purchased` and a nullable `price_credit` override.

Not columns on `wot_grind_steps`, for two reasons. Tier XI vehicles sit above
every tier X target and belong to no research path, so hanging their state off a
step would have meant inventing steps — and every XP total on the other tabs is
reconciled against the spreadsheet, so nothing may be added to a path. And a
tank worth buying is not always one on a tracked path.

Rows only exist once something has been said about a tank. Defaults otherwise:
purchased if the account has battles in the vehicle (the same signal that
truncates a research path, so the two agree by construction) or if it is the
line's position zero. Buying implies unlocking, enforced in the controller
rather than trusted from the client.

### Tier XI

The API does carry tier XI — 28 vehicles, all 7,400,000 credits — and 27 tier Xs
have `next_tanks` pointing at one. `PurchaseBoard` resolves that successor per
line and appends it as a cell, without creating a grind step. 7 of the 17
tracked lines have one. A test pins that the path stays three steps long.

### Prices

Cells default to the encyclopedia price and are typed over when a seasonal
selectable discount applies. The override is nullable, so clearing it restores
the shop price rather than recording a free tank; a `×` appears beside an
overridden price to do exactly that. `EditableNumber` grew `url`/`only`/`tone`
props for this — it was hard-wired to the grind-step endpoint.

### One credits figure, not two

`totals.credits_required` now comes from the purchase board rather than from
`WotGrindTarget::creditsRequired()`, so the headline card, the tab footer and
the visible rows cannot disagree. It sums the *visible* rows, which means buying
a line's last vehicle settles that line even if an intermediate tier was never
ticked — defensible because you cannot research past a vehicle you do not own.
A test caught the first cut of this, where the total was summed after the
bought-out rows were dropped while the docblock claimed the opposite.

`credits_remaining` rejects purchased cells rather than skipping position zero,
so un-ticking the vehicle you are grinding in puts its price back on the bill
instead of leaving a Buy button over a cost nothing counts.

Suite: **169 passed, 779 assertions.**

### Purchase board: empty cells, and columns that have outlived their use

Two follow-ups.

**Tiers below a line's start were blank, not zero.** `TechTree::pathTo()`
truncates a path at the vehicle being played, so a line starting at tier IX has
no tier VIII step — and the matrix rendered that as an empty cell, reading as
"nothing here" when it means "bought long ago". You cannot reach a tier IX
without researching the VIII, so those vehicles are owned whether or not they
are still in the garage.

New `TechTree::ancestorsOf($tankId, $downToTier)` walks the predecessor map
downwards; `PurchaseBoard` prepends the result as cells that default to bought.
The floor is the lowest tier any line actually starts at — going lower would
only manufacture columns the next rule immediately drops.

**A tier every line has bought is no longer a column.** `tiers()` now rejects
any tier whose cells are all purchased. Those cells stay in the payload and
simply go unrendered, so nothing is lost if one is later un-ticked. On the live
board this drops tier VII entirely and leaves VIII–XI.

Together these answer the same complaint from both ends: CS-63's tier VIII now
reads `0` instead of blank, and tier VII — which was all zeroes — is gone.

One test fixture had to be rewritten rather than patched. It marked a tier VIII
unbought to keep that column alive while asserting the same tank read as bought
on another line, which cannot happen: purchase state is per tank, not per line.
The replacement gives the two lines genuinely different starting tiers instead.

Suite: **171 passed, 817 assertions.**

### Tanks to Purchase covers tanks with no tracked line

Reported: "several tanks I don't own yet not listed on the board (e.g. E 50 M)".

Not a staleness problem — `wot:snapshot` reported no new battles, and the
vehicle data was hours old. The board simply built its rows from the 17 tracked
grind targets, and the E 50 Ausf. M is not one. The account has played the E 50,
so the E 50 M is a single research step away and is exactly the case the earlier
instruction meant by "there may be a tank I can buy that I am not actively
grinding" — under-implemented at the time as "not filtered to `is_active`".

`PurchaseBoard` now emits two kinds of row:

- **tracked lines**, keyed `t{target_id}`, built from their steps as before;
- **untracked buyables**, keyed `v{tank_id}` — any non-premium vehicle the
  account has not played whose immediate predecessor it *has* played, with its
  lineage filled in from the tech tree.

A tracked target is never also emitted as a candidate. Rows carry a string
`key` now rather than a target id, since the two kinds share one list.

`TechTree::predecessorOf()` was added for the "researchable now" test; using
`ancestorsOf($id, $tier - 1)` for it would have worked by accident rather than
by intent.

**Two floors, not one.** Filling a line's lower tiers and deciding which
untracked vehicles are worth listing were the same number in the first cut,
which broke in a case the live data does not exercise: with no tracked targets
at all there is no lowest step tier, and the board collapsed to tier XI only.
They are now separate — `config('wargaming.purchase_min_tier')` (8) gates
untracked rows, while columns reach down to whichever is lower of that and the
lowest tier a tracked line starts at, so a tracked line is always shown in full
however low it begins. Two tests pin both directions.

Live board: 55 rows, 17 tracked and 38 untracked, tiers VIII–XI, built in
~120 ms. Tier VII no longer has a column — its only untracked occupants were
below the floor, and every remaining tier VII cell is owned.

Suite: **178 passed, 893 assertions.**

### Purchase filters, and per-tier totals

A filter row between the tabs and the table: nation as flag buttons (rows), tier
as Roman numerals (columns). Both multi-select, everything on by default.

**Held as what is hidden, not what is selected.** Buying a tank can retire a
nation from the board or collapse a tier column, and the set of options is
therefore not stable across a request. A selected-set would have to guess
whether an option that reappears was meant to be on; an inverted set makes
"everything on" the resting state, keeps explicit deselections across reloads,
and leaves stale entries harmless. `All` clears a row's exclusions, and only
appears when there is something to clear.

Filtering is client-side. Every figure is already in the payload, the page does
no round trip for it, and the totals are derived from the same cells the table
renders — so they cannot disagree with what is on screen.

**Hiding a tier removes it from the totals**, rather than only from view. A
filter that changed what you can see but not what you owe would be a worse
answer to "what would this cost me". Per-tier totals were added to the footer at
the same time: with nothing hidden they sum to the figure the server computed
independently (12,730,000 + 49,870,000 + 244,000,000 + 192,400,000 =
499,000,000), which is the check that the client arithmetic matches PurchaseBoard.

A row is hidden once the visible tiers hold nothing it still has to pay for —
the same rule the server already applies to a bought-out line, applied to the
tiers on screen rather than to all of them. Deselecting tier XI takes the live
board from 55 rows to 42; selecting tier XI alone leaves 26. With every tier on
the count is unchanged at 55, so nothing is hidden at rest.

The headline "Credits needed" card deliberately does *not* follow the filters —
it is a page-level summary rendered on all five tabs, and a tab-local filter
should not silently rewrite it.

### Duplicate rows on the purchase board

Reported via Type 68 and Type 71 appearing as separate rows. (The tiers are one
lower than they looked: the encyclopedia has Type 68 at IX, Type 71 at X, and
STK-2 as the tier XI above them.)

The candidate filter excluded tracked *targets* — `$targets->pluck('tank_id')` —
but not the vehicles along their paths. Type 68 is a mid-path step on the
tracked Type 71 line and is also researchable-now in its own right, so it earned
a second row. 11 vehicles were affected, in pairs that each rendered the other's
line: the Object 430 Version II row carried K-91 as its tier X successor while
the K-91 row carried the Object 430 Version II as its tier IX step.

The credits were being double-counted with them. The board's total was
499,000,000; it is 447,080,000 once each vehicle is charged once. Rows: 55 → 49.

The fix collects every `tank_id` appearing in any *cell* of a tracked row, not
just the target ids, and excludes those from the candidate pool. Tracked rows
are therefore built first now.

A second guard drops any candidate that is an ancestor of another candidate, so
only the topmost of a branch keeps a row. Cycle-safe because a lineage strictly
descends in tier. This is defensive rather than load-bearing: a candidate
requires its immediate predecessor to be played, and a played vehicle is not
itself a candidate, so the case is hard to reach with well-formed data — but it
costs one pass and the alternative failure is silent double-counting.

Suite: **180 passed, 917 assertions.**

## 2026-09-09 — Actions bumped off the Node 20 runtime

Last night's production deploy logged:

> Node.js 20 is deprecated. The following actions target Node.js 20 but are
> being forced to run on Node.js 24: actions/cache@v4, actions/checkout@v4,
> actions/setup-node@v4, webfactory/ssh-agent@v0.9.0.

The runner is already executing them on Node 24 — the warning is notice that the
compatibility shim goes away. Bumped in both `ci.yml` and `deploy.yml`:

| action | was | now |
| --- | --- | --- |
| `actions/checkout` | v4 | v7 |
| `actions/cache` | v4 | v6 |
| `actions/setup-node` | v4 | v7 |
| `webfactory/ssh-agent` | v0.9.0 | v0.10.0 |

`shivammathur/setup-php@v2` is a rolling major that already declares `node24`,
which is why it stayed out of the warning and stays unpinned here.

The lowest versions that would have silenced the warning are checkout v5, cache
v5, setup-node v6 and ssh-agent v0.10.0. Going to the current majors instead
avoids repeating this in a few months, and the intervening breaking changes were
checked against these two workflows specifically:

- **checkout v7** blocks checking out a fork PR under `pull_request_target` and
  `workflow_run`. Neither workflow uses those triggers — CI is `push` /
  `pull_request` / `workflow_dispatch`, deploy is `push` / `workflow_dispatch`.
- **checkout v6** writes the git credential to a separate file. Nothing here
  runs git after the checkout; the deploy authenticates with its own SSH key via
  ssh-agent, not the `GITHUB_TOKEN`.
- **setup-node v6** narrowed automatic caching to npm only. Both call sites
  already pass `cache: npm`, so that is exactly what survives.
- **cache v6** and **setup-node v7** are ESM migrations with no input changes.

checkout v5+ and cache v5+ require runner ≥ 2.327.1, which only matters for
self-hosted runners; both jobs are `ubuntu-latest`.

Deploy is the workflow that actually proves this — its ssh-agent step is the one
action here with no CI coverage, so the first push to `main` after this is the
real check.

## 2026-09-09 — Germany's flag reverts to the CDN icon

Reverses the substitution described in "Germany is not the CDN icon" above.
The generated Bundesflagge is out; `public/images/nations/germany.png` is once
again the actual Wargaming asset, pulled fresh from the same CDN path (still
live at the `6.16.0_bbf399` build hash months later).

The prior entry identified the CDN icon's disc emblem as a swastika and
excluded it on that basis. The user confirmed in review that it is a
different, non-Nazi historical variant, and asked not to have content calls
like this made unilaterally going forward. At 29x18 with heavy fabric-wave
shading the disc is genuinely hard to read with certainty either way — noted
here for whoever looks at this file next, not as a re-litigation of the call.


## 2026-09-09 — APP_TIMEZONE moved to America/Chicago, and news syncs three times a day

Two requested changes that turned out to be entangled.

### The schedule

`wot:sync-news` moved from `twiceDaily(6, 18)` to 07:00 / 12:00 / 17:00. Written as a
cron expression with an explicit `->timezone('America/Chicago')` rather than inheriting
`app.timezone`: these are wall-clock times chosen to suit a working day, so they should
stay put if the app timezone moves again. `schedule:list` renders it as
`0 12-22/5 * * *` — the UTC translation, and the check that it means what it should.

None of the three sit near 02:00, which matters for a non-UTC schedule: spring-forward
would skip a 02:00 task and fall-back would run it twice.

### The timezone, and why it was not a one-line change

`config/app.php` had `'timezone' => 'UTC'` **hardcoded, with no `env()` call**, so setting
`APP_TIMEZONE` in `.env` would have been a silent no-op. That was the first surprise.

The second was worse. These datetime columns are `timestamp` — no zone — so a row holds a
bare wall clock and nothing records which zone wrote it. Eloquent's two halves then
disagree, and the asymmetry is visible in the framework source:

| | what it does | source |
|---|---|---|
| write | `asDateTime($value)->format()`; for a Carbon it hits `Date::instance($value)`, returned **untouched**, so it formats in whatever zone that instance already carries | `HasAttributes::fromDateTime`, line 1625 |
| read | `Date::createFromFormat($format, $value)` with **no `$tz` argument**, so PHP applies `date_default_timezone_get()` — i.e. `app.timezone` | `HasAttributes::asDateTime`, string branch |

In most Laravel apps this never surfaces: values come from `now()`, are written in app time
and read as app time, and stay consistent — which is exactly why the change looked routine.
This app is the exception. Its timestamps come from Wargaming in UTC, and both producers pin
UTC regardless of `app.timezone`:

- `FeedParser::date()` — an RSS `pubDate` carries `+0000`, so `Carbon::parse()` keeps that
  offset instead of adopting app time.
- `EventExtractor` — `createFromTimestampUTC()` and `createFromFormat(..., 'UTC')`.

So flipping `APP_TIMEZONE` alone would have moved only the read side. The same stored bytes,
under both settings:

```
app.timezone=UTC              write=2026-09-09 09:00:00  read=...T09:00:00+00:00  -> browser shows 4:00 AM
app.timezone=America/Chicago  write=2026-09-09 09:00:00  read=...T09:00:00-05:00  -> browser shows 9:00 AM
```

Nothing about the row changed — only its interpretation. Every article and event would have
read five hours late, new rows included.

### What was actually done

1. `config/app.php` → `env('APP_TIMEZONE', 'UTC')`, with the asymmetry documented at the
   config value where someone changing it will read it.
2. `APP_TIMEZONE=America/Chicago` in `.env` and `.env.example`, and in production's
   `shared/.env`.
3. `FeedParser` and `EventExtractor` now `->setTimezone(config('app.timezone'))` before
   returning. The instant is unchanged; only its expression moves.
4. A migration, `convert_timestamps_from_utc_to_app_timezone`, rebasing existing rows.

The migration discovers its targets from `information_schema` rather than listing them — 44
zone-less timestamp columns across 17 tables, which is more than the news tables because every
`created_at` in the database was written by a UTC `now()`. It converts in Postgres via
`AT TIME ZONE 'UTC' AT TIME ZONE 'America/Chicago'` so the offset used is the one actually in
force on each row's own date; the articles span 2023–2026 and straddle several CST/CDT
boundaries, so a flat five-hour subtraction would have been wrong for roughly half of them.
It no-ops on non-pgsql so the SQLite test database, which is created empty each run and has no
legacy data, is unaffected.

### Tests

Three existing assertions compared `starts_at->toDateTimeString()` against a UTC wall clock
and failed — correctly, and by exactly the offset. They now assert `->utc()->toDateTimeString()`,
which pins the instant the source stated rather than whatever `app.timezone` happens to be.
A new test covers the normalisation itself. **15 passed, 42 assertions.**

### What the frontend was already doing

Worth recording, because it nearly made the whole change unnecessary: every date in
`resources/js/wot/` renders through `toLocaleString(undefined, ...)`, where `undefined` means
the *viewer's* timezone. With storage in UTC and a correct `...Z` on the wire, Central times
were already being displayed. The change is still coherent — server-side times, logs and
`now()` are now Central too — but the dashboard looked the same before and after, and that is
the expected outcome rather than a sign it did not work.

## 2026-09-09 — Tabler icons added to the WoT sub-project

Evaluated Font Awesome alternatives at the user's request (Lucide, Heroicons, Tabler, Phosphor
— all MIT, all free with no Pro paywall, unlike Font Awesome's free tier). User picked Tabler
for its size (~5,900 icons) and coverage of dashboard-y glyphs.

Installed `@tabler/icons-vue@3.46.0` — per-icon Vue 3 components, tree-shaken by Vite so only
icons actually imported ship in the bundle. Scoped to the `resources/js/wot/` island only; the
base app stays icon-library-free per the "no framework in the base app" rule — a future Blade
sub-project wanting icons should evaluate inline SVG or a webfont build rather than assuming
this package is available outside `wot/`.

Test case: `Grinding.vue`'s Tanks-to-Purchase Unlock/Buy button now renders `IconLock` /
`IconShoppingCart` (from `@tabler/icons-vue`) next to the existing label, switched by the same
`is_unlocked` condition that already drove the label text and border color. Presentational
only — no props, backend, or test assertions changed; `GrindingTest.php` still passes
(49 passed, 390 assertions) since it never asserted on button markup.

## 2026-09-09 — Pinning is a grouping mechanic, not its own order

`WotArticle::scopePinnedFirstFor()` dropped its `ORDER BY wot_article_pins.pinned_at DESC`
tier. Pinned articles still hoist above unpinned ones, but now sort among themselves the same
way unpinned ones do — by `published_at DESC`, then `id DESC`. Re-pinning still refreshes
`pinned_at` (needed for "is this pinned" state and to avoid the unique-constraint error) but
that refresh no longer moves the article's position.

`ArticlePinTest.php`'s pin-ordering tests were written against the old behavior and asserted
pin-recency ordering; updated to assert publish-date ordering instead (`orders several pins by
published date, not by when they were pinned`), and the idempotent re-pin test dropped its
now-untrue "moves back to the top" assertion, keeping only the unique-constraint check.

## 2026-09-09 — Dashboard's Latest tab no longer hoists pinned articles

Follow-up to the entry above. The dashboard news panel's Latest tab was still calling
`pinnedFirstFor()`, which — even after the previous change — still hoists pinned articles
above unpinned ones as a group before sorting by `published_at`. The user wants Latest to be
exactly the newest articles, full stop; pinning should only affect the separate Pinned tab and
the `/wot/news` page.

Split `pinnedFirstFor()` into two scopes: `withPinnedFor()` does just the left join and
`pinned_at` select (so a row can still report `is_pinned` and drive the pin/unpin toggle), and
`pinnedFirstFor()` now calls `withPinnedFor()` and adds the hoisting `ORDER BY` on top.
Dashboard's Latest query switched to `withPinnedFor($user)->inDefaultOrder()`; its Pinned tab
and `/wot/news` keep `pinnedFirstFor()` since hoisting-then-filtering (or filtering to
already-pinned rows) is exactly what those still want.

`DashboardTest.php` had two tests asserting the old hoist-on-Latest behavior; updated to
assert plain newest-first with `is_pinned` still reported per row.


## 2026-09-09 — Tanks to Purchase: input groups, optimistic updates, and a cascade-layer trap

UI pass over the purchase board, plus two real bugs found on the way.

### The cell is now one input group

`[lock toggle] [price] [x] [buy]`, joined with `gap-px` and `items-stretch`, so
the buttons take their height from the field rather than from their own padding
and the group has one flat top and bottom edge. The buttons are icon-only to keep
the cell narrow, which leaves `title` and `aria-label` as the only things naming
the action — dropping either would leave a screen reader announcing "button".

The lock is a single toggle rather than two controls: open padlock to research,
closed padlock to undo. Both icons name the *action*, not the current state.

`w-20` and `text-sm` were measured rather than guessed. `@tailwindcss/forms` puts
`font-size: 1rem` on text inputs in the base layer, so these never inherited the
table's 14px and rendered a size larger than every figure beside them. At 14px,
Instrument Sans puts "6,100,000" at 67.6px of text, 77.6px with `px-1` and the
border — so 80px holds any realistic price. The validation ceiling (100,000,000,
97.2px) does not fit, and did not fit at the old `w-24`/16px either.

### Optimistic updates

Every figure on the tab derives from the `purchase` prop client-side, so flipping
one cell locally redraws the icons, that cell's cost, and the row, tier and grand
totals without waiting for the round trip. Inertia 3.7's `optimistic` option;
rollback on failure is automatic.

Two things are deliberately **not** reproduced client-side, and should stay that
way: the headline credits card reads `totals`, and `PurchaseBoard` settles a whole
line when its last vehicle is bought — including tiers never ticked off. That is a
server rule, and a second copy of it here would have to be kept in step. A
bought-out row therefore lingers for the length of the request instead.

`EditableNumber` takes the callback as a prop rather than building one, because
applying it means knowing the shape of the props the field feeds and the component
serves both the step rows and the purchase board. The five step fields deliberately
do **not** pass one: their totals are computed server-side, so an optimistic patch
would update only the number already visible in the input.

### Bug: un-buying dropped two steps instead of one

Clicking the `0` to mark a vehicle as not bought flashed the correct state and then
reverted to unresearched. `PurchaseBoard::cell()` resolves
`$purchased = $purchase?->is_purchased ?? $ownedByDefault` and derives `is_unlocked`
from *that*, so a vehicle that reads as bought because it sits in the garage has no
stored flag behind it. Writing `is_purchased = false` left `is_unlocked` at its
default of false.

Fixed in `GrindController::updatePurchase`, beside the invariant it mirrors: buying
already implied researching, and now un-buying preserves it. An explicit
`is_unlocked` in the same request still wins, so a deliberate re-lock is unaffected.
The test was written first and watched fail on exactly that assertion.

### Trap: an unlayered SFC style outranks every Tailwind utility

Colouring the researched cell had no visible effect at all, twice over, and the
cause is worth knowing about before it costs someone another hour.

`AppShell.vue` carries a deliberately unscoped `<style>` block, and it held:

```css
.wot input[type='text'], .wot input[type='search'], .wot select {
    background-color: ...; border-color: ...; color: ...;
}
```

**Cascade layers outrank specificity.** Unlayered rules beat everything inside
`@layer`, and Tailwind puts every utility in `@layer utilities` — so that rule
silently won against any `bg-*`, `border-*` or `text-*` a component set on its own
field, no matter how specific. `focus:border-wot-gold` had never painted either.
No amount of selector weight fixes this; only layering does.

Moved into `@layer base`, which is where a default belongs and is the only thing
that lets a component override one. `app.css` declares
`@layer theme, base, components, utilities`, so `base` sits before `utilities`.

The knock-on: utilities now actually win, so anything naming no colour would have
come out transparent — that was `EditableNumber`'s original intent, never realised
on these pages. Its default `tone` now restates the shell's colours explicitly so
nothing else moved.

### Two verification lessons from this session, recorded because both produced wrong claims

**`public/build` is not what the browser reads during development.** With
`composer run dev` running, `public/hot` exists and the page loads from Vite on
:5173. Several "verified in the built CSS" checks this session inspected a file the
browser never opened. Check `http://localhost:5173/resources/css/app.css` instead —
it comes back as a JS module with the CSS as an escaped string on one line, so
`grep -c` on it counts nothing useful; decode it first.

**Reading a class off the source does not mean it renders.** The claim that the
price text "was already green" came from reading `:tone` rather than from what
painted, and the AppShell rule above meant it never had been.

## 2026-09-09 — Vehicle-type art self-hosted for the Blueprints/Grinding icons

User wanted icons for the five WoT vehicle classes (`lightTank`, `mediumTank`, `heavyTank`,
`AT-SPG`, `SPG` — the values `WotVehicle.type` actually holds) to use on the Grinding page.
No icon library, generic or WoT-specific, has these — "light tank" isn't a shape anyone but
Wargaming draws, so a Tabler/Lucide/etc. set can only offer role metaphors (crosshair for a
TD, shield for a heavy), not the real symbol. Compared that option against pulling
Wargaming's own art; user chose WG's art, same call as "Nation flags replace nation
slugs" above.

Found via the public tankopedia page's network requests, not documented anywhere:

    https://na-wotp.wgcdn.co/static/6.16.0_bbf399/wotp_static/img/tankopedia_new/
      frontend/scss/tankopedia-main/img/{lighttank,mediumtank,heavytank,at-spg,spg}.png

Same CDN host and same stale build hash (`6.16.0_bbf399`) as the nation flags, different
path underneath — confirms the hash is pinned per static deploy rather than per asset type,
so this path will 404 whenever WG's next frontend deploy rotates it, exactly like the flags.
All five returned 200, 194×132 PNGs, 4–8 KB each. Self-hosted under
`public/images/vehicle-types/` (not `.gitignore`d, same as `public/images/nations/`) rather
than hot-linked, for that reason.

**These are full painted illustrations of a representative tank per class, not a compact
badge** — each carries the small in-game class glyph (diamond, chevron, etc.) floating above
the vehicle, but only baked into this composite art; no standalone badge asset was found
anywhere on WG's CDN. Fine for a legend or a larger "what is this class" callout; too
detailed to drop inline into a table row at icon size — that still wants a decision before
wiring into `Grinding.vue`.

No Nazi-flag-style content in any of the five (unlike `germany.png` — see "Nation flags
replace nation slugs" above) — checked all five by eye before committing them.

### Wired into Tanks to Purchase

User wanted the icon inline after all, at the size the illustrations were flagged above as
too detailed for. Wired it in as-is rather than cropping or re-picking icons — it reads fine
scaled to `NationFlag`-sized (16×24px) in practice, so the earlier concern didn't hold up once
tried on screen.

- `WotVehicle.type` (`lightTank`/`mediumTank`/`heavyTank`/`AT-SPG`/`SPG`, the API's own
  values, already a column) is now surfaced on `PurchaseBoard`'s row payload as `type`,
  read off `$namedBy` — the same vehicle the row's own `name` comes from — rather than the
  row's tier-VIII floor, so the icon always matches the name next to it.
- New `VehicleTypeIcon.vue`, mirroring `NationFlag.vue`'s shape: a local map from API value
  to filename/label (no `HandleInertiaRequests` sharing needed here, unlike nations — these
  five values are fixed by the game's own rules, not something a future patch adds to).
  Renders nothing for an unrecognised type rather than falling back to text, since the name
  and tier already carry the row on their own.
- Placed right of the row name in the Tanks to Purchase table only (`Grinding.vue`'s other
  tank-name row, on the Active Grinding tab, was left alone — not asked for).
- `techLine()`'s tier X fixture gained an explicit `type: 'mediumTank'` (it was random via
  the factory before) so the new assertion in "lays the purchase board out as one column per
  tier" isn't flaky. **51 passed, 429 assertions.**

## 2026-09-09 — The tankopedia illustrations were the wrong asset; the real icon was CSS-only

User flagged the icons wired in above as wrong on sight. They were: `tankopedia-main/img/
{type}.png` is real WG art, correctly named, but it's a full painted tank illustration meant
for a landing-page filter *button*, not a compact badge. At 16×24px five different tan/green
paint jobs collapse into indistinguishable blobs — confirmed by actually resizing one and
looking, which should have happened before wiring it in rather than after.

The icon actually shown in the tech-tree nav (`ico-vehicle-type ico-vehicle-type__lighttank`,
per the user pointing at the class name directly) isn't a separate file at all. It's an inline
base64 SVG baked into a `background` rule inside WG's compiled `main.css`
(`na-wotp.wgcdn.co/static/6.16.0_bbf399/wotp_static/css/main.css`, 2 MB, one line) — a static
scan of the page's `<img>`/asset references was never going to find it, because it isn't one.
Decoding `.ico-vehicle-type__{lighttank,mediumtank,heavytank,at-spg,spg}` turned up five tiny
single-path shapes matching the game's real class glyphs: a diamond for light, a stacked
double-diamond for medium, a triple-chevron for heavy, a downward triangle for a tank
destroyer, a square for SPG — the actual small badges the composite illustrations had been
carrying (unusably tiny) in their corner the whole time.

**Fixed:**
- `public/images/vehicle-types/*.svg` replaced with the five decoded SVGs (a few hundred
  bytes each, vs. 4–8 KB PNGs), `fill` changed from WG's tan (`#DFD9B7`) to `currentColor`.
- `VehicleTypeIcon.vue` no longer renders an `<img src>` — an external SVG referenced that way
  can't inherit `currentColor` in any browser, so the shapes are now inlined directly in the
  component's template (path data + viewBox per type, copied from the decoded files) and
  colored via a wrapping `text-wot-dim` class, matching how every other muted label on the row
  (the tier suffix, dim text) already gets its color. The files under `public/images/
  vehicle-types/` are kept anyway, as a record of where the shapes came from, same spirit as
  keeping `germany.png` as a committed asset rather than only in a generator script.
- No backend or test change — `type` was already the right field, only the art was wrong.

**Lesson:** a name like `ico-vehicle-type` or a filename like `at-spg.png` reads as "the
thing," but WG's frontend serves the same semantic label from more than one asset shaped for
different jobs (landing-page filter vs. inline badge). Confirm by rendering at the actual
target size before wiring in, not after — the same "reading a class off the source does not
mean it renders" trap as above, one asset pipeline over.


## 2026-09-09 — Tanks to Purchase covers the whole tree, and shares are counted once

Two changes that only make sense together: the board now reaches tier I, and a vehicle sitting on
more than one line is billed — and editable — exactly once.

### The floor, and why lowering it alone would have done nothing

`$floor` was `min(purchase_min_tier, lowest tracked step tier)`, which evaluated to **7** on the
live board. Tier VII cells were already being built. They never rendered, because `tiers()` dropped
any tier whose cells were all purchased and backfilled ancestors default to owned — so the column
was dropped, and a dropped column is one you cannot un-tick anything in. Lowering the floor by
itself would have added no visible column at all.

`$floor` is now the constant `PurchaseBoard::FLOOR_TIER = 1`. **This supersedes the "Two floors,
not one" decision recorded above**, rather than reversing it by accident: that computation existed
to answer "how low does any tracked line start?", which stops being a question once the answer is
always the bottom of the tree. `config('wargaming.purchase_min_tier')` is untouched at 8 and still
does its own separate job — gating which vehicles earn a row.

### A latent double-count, fixed before it could bite

Eight vehicles already appeared in two rows each. Nothing double-charged, but only by luck: every
occurrence was purchased, and `credits_remaining` skips bought cells. Un-ticking any one of them
would have billed it twice and rendered the same toggle twice on screen — the `6794af2` mid-path
bug displaced from row heads to ancestor cells, which neither `$covered` nor `$subsumed` guards
(both test candidate *heads* only).

At floor 1 that goes from latent to structural: **167 shared cells across 76 vehicles** on the live
board. The MS-1 alone sits on twelve lines.

New `claimShared()` walks the rows in display order. The first row to show a vehicle keeps it as an
editable cell and pays for it; later rows carry it as `is_shared` with `shared_with` naming the
owner, and it contributes nothing to their `credits_remaining`. Row totals therefore still sum to
the grand total.

**Ordering is the whole trick, and it forced a resequence of `for()`.** "First" has to mean first
*on screen*, so claiming runs after the sort — which meant `credits_remaining` could no longer be
computed in `row()`, because that runs before anything is sorted. `row()` keeps `cells` and
`is_bought_out`; `claimShared()` is now the single place the money is totalled. Bought-out rows are
still rejected *before* claiming: a row nobody can see must not take a vehicle from a row they can,
or the survivor would point at a line that is not on the board and nothing would pay for the tank.

Verified against live data rather than reasoned about: the M2 Light appears in **4** rows, and
un-ticking it moves `credits_required` by 3,400 once, not 13,600. Run inside a transaction and
rolled back.

### The board is now complete; the filter decides what you see

`tiers()` became `tierColumns()` and no longer rejects anything — every tier holding a cell gets a
column. A sibling `bought_tiers` names the tiers where nothing is still owed, and the client seeds
`hiddenTiers` from it, so a settled tier's filter button renders but starts unselected. One
mechanism instead of two.

The settled predicate is `is_purchased || is_shared`, not just `is_purchased`. A shared duplicate
costs nothing, so a column held open only by duplicates would be a column of zeroes — exactly what
the original rule existed to keep off screen.

That predicate is also load-bearing for something non-obvious. `shownRows` hides a row with nothing
left to pay in the *visible* tiers, so hiding tiers by default could in principle hide rows. It
cannot: a row owes at tier T only if it has a cell there that is neither purchased nor shared,
which is precisely what stops T being in `bought_tiers`. Break that symmetry and the default filter
starts silently removing rows.

The seed is a one-time initialisation in `<script setup>`, never a watcher. Setup runs once per
component instance and every purchase here is a partial reload that updates props on the existing
instance, so it fires on a real visit and never while you click. A watcher would re-seed on every
response and make a column vanish the instant you bought it out.

### Sticky Line and Remaining columns

Eleven tier columns do not fit, so the first and last columns pin while the tiers scroll between
them. Three things this needed that are easy to miss:

- **Opaque backgrounds.** `--color-wot-panel` and `--color-wot-sunken` are both translucent, so a
  sticky cell painted with either lets the scrolling columns show through it. `panel-solid` already
  existed; `--color-wot-sunken-solid: #0b161e` is new — sunken pre-composited over panel-solid,
  because one element cannot stack two background colours.
- **`border-collapse` fights sticky.** Preflight sets it on every table, and with collapsed borders
  a sticky cell's border does not travel with it. The purchase table is now `border-separate
  border-spacing-0`; the `divide-*` rules put their borders on rows, so they were unaffected.
- **Row hover cannot paint through a cell with its own background**, hence `group` on the `<tr>` and
  `group-hover:` on the two sticky cells.

### Result and cost

Live board: 48 rows (unchanged), **217 → 505 cells**, tiers `1–11`, `bought_tiers` `1–7`,
`credits_required` unchanged at 440,980,000 — expected, since every current purchase record is tier
VIII+, so no low-tier ancestor is un-ticked. Build **42 ms**.

The real cost is payload, not CPU: `purchase.rows` roughly triples, and it ships on every purchase
patch via `only: ['purchase', 'totals']`. Fine for one user; the lever, if it ever matters, is
omitting `name`/`api_price` on purchased cells.

**Not fixed here, and worth knowing:** `TechTree::predecessors()` keeps only the *cheapest*
predecessor per vehicle, so the tree is modelled as single-parent chains. Real sharing is therefore
under-reported — 76 vehicles is a lower bound, and a vehicle reachable from two lines is attributed
to whichever unlocks it cheaper. The board is not authoritative on which lines share a vehicle.

Suite: **187 passed, 1045 assertions.**


## 2026-09-09 — Owned lines stay on the board, behind a filter

Reported: "it is removing lines that I fully own". Two things were removing them — the server
rejected any row whose top cell was bought, and the client hid any row with nothing left to pay.
Both are gone; the board now shows every line and an **Owned** filter puts the finished ones away,
matching how Nation and Tier already work.

### The seam that had to be closed first

Three lines were being hidden, and displaying them naively pushed `credits_required` from
440,980,000 to 447,830,000. Two of them held an unbought tier IX under a bought tier X — the
Obj. 140 line wanting 3,450,000 for a T-54, the EBR 105 line 3,400,000 for an EBR 90.

The first instinct was to correct the stored data. There was nothing to correct: **neither tank has
a purchase record at all.** They read as unbought because `played` means *in the garage with
battles*, and selling a tank after moving up the line takes every trace of having owned it. The
board already knew better in one place — backfilled ancestors default to owned precisely because
you researched through them — and simply did not apply that reasoning to steps or to a candidate
sitting under its own successor.

So the rule moved up to the row, where both paths meet:

> anything below a vehicle you own was owned too, because you cannot research past a vehicle
> without having owned it.

An explicit purchase record still wins, so un-ticking a tier you sold and want back keeps working;
only cells with nothing stored about them are inferred. With that in place the three lines are
fully owned, contribute nothing, and `credits_required` is unchanged at 440,980,000 — the 6,850,000
was never a real debt, just an inference the board was not making.

`is_bought_out` came out entirely. It existed only to drive the reject, and "settled" is now simply
`credits_remaining === 0` — which is also what the client's filter tests, so the flag would have
been a second definition of the same idea.

### Claiming needed a second pass

Owned lines staying on the board broke an assumption in `claimShared()`. A settled line shows the
same low tiers as a line still working up to them and sorts wherever its name puts it. Claiming
strictly in display order, it could take a shared cell by arriving first — contributing nothing
because it owns the tank, while the row that still owed carried it read-only and contributed
nothing either. The price would have dropped off the board silently.

Ownership now runs in two passes: rows that still owe a vehicle get first refusal, and only then
does anything nobody owes fall to the first row showing it. Pinned by
`it('never lets an owned line claim a shared vehicle from one that still owes')`.

Ownership is also keyed on the row `key` rather than its name now. Rows are named after their tier
X, two lines could share one, and the sort's tie-break already assumes names can collide.

### Result

Live board: **48 → 51 rows**, 3 fully owned, `credits_required` unchanged at 440,980,000. The
filter only appears when there is something to hide, and says how many lines it would put away.

Suite: **187 passed, 1059 assertions.**


## 2026-09-09 — Tanks to Purchase is the tech tree, not a projection of your targets

Reported, in order: the Owned filter did nothing; the Sheridan line was missing; and then the
framing that explains both — *"the board should show all tanks in the entire tech tree. The notion
of adding targets is a misnomer for this table."*

The filter was working. There was no Sheridan row to show, and there never had been. Rows came from
three places, and none of them could produce that line:

- **tracked targets** — the Sheridan was not one;
- **candidates**, meaning anything one research step from something played — a candidate is
  something you could *buy next*, so a vehicle you already own can never be one;
- and nothing else.

31 tier X vehicles were owned; **16 had no row at all**, and every one of the 16 had no successor.
They sit at the top of their branch, so nothing above them is buyable, so no candidate row could
ever exist for them. Kranvagn, STB-1, Type 5 H, T110E3, Obj. 268/4, Sheridan.

### The reframe

`PurchaseBoard` now enumerates the tree: **one row per branch top**, a vehicle nothing else
researches from, with its whole lineage behind it. `for()` no longer takes `$targets` at all.

`targetRow()`, `candidateRows()`, `ownedRows()`, `$covered` and `$subsumed` all collapsed into one
`lineRows()`. Those five existed to answer "which lines are worth showing?", which stops being a
question when the answer is all of them. Deciding what to look at moved to the filters, where the
user put it.

The tier floor earns a second job: 159 vehicles unlock nothing, but 86 are tier II–VII dead ends
that are branch tops only in the technical sense. `purchase_min_tier` keeps them out, leaving the
72 lines the tree actually ends at.

**Ownership got simpler and more honest as a side effect.** It used to be positional — an ancestor
cell was owned because it was backfilled, a step was owned because it was position zero. Now a cell
is owned if the vehicle has been played, and the rule added earlier that day fills in the rest:
anything below something owned was owned to reach it. The same tank no longer reads bought on one
line and unbought on another, which was a real inconsistency the old scheme produced and one of its
tests actually pinned.

### The headline card follows the board now

`credits_required` is the cost of the entire tree — the honest number for a board that is the
entire tree, and a useless one to hold against your balance. The "Credits needed" card reads the
client's filtered `grandTotal` instead, so narrowing to a nation or hiding what you own answers
"what would finishing this cost me?". The server total still drives nothing else.

### Tests

Eight pinned concepts that no longer exist — candidate gating, tracked-versus-candidate dedup, the
two-floor rule, the tier XI successor push — and were removed with approval rather than contorted
into new shapes. Four fresh ones replace them: every line gets a row tracked or not, a line you own
outright shows owing nothing, the floor decides what counts as a line, and the board is unchanged
by adding a grind target.

Two survivors needed repointing rather than deleting. `it('names a purchase row after its tier X')`
now proves the rule where it still bites — a line running on to a tier XI is headed by the XI and
named for its X. And the researched-past test now pins the *better* behaviour: both lines agree
about the tank they share, and one of them claims it.

The fixtures moved with the model: `purchaseLine()` marks its tier VIII played rather than relying
on position zero, and `branchedLines()` its tier VII. That one change took the failures from 17 to
10 — the fixtures were expressing "you already own the bottom of this line" in the old vocabulary.

### Result

Live board: **51 → 72 lines**, 20 fully owned, 702 cells of which 269 are shared duplicates, tiers
1–11, built in ~50 ms. Whole-tree cost 491,320,000; the card shows whatever the filters leave.

Suite: **182 passed, 1011 assertions.**


## 2026-09-09 — Collector's vehicles are not lines

Asked about the 113 and the AMX 30 appearing as lines. They are collector's vehicles, and the
board should not carry them.

They turn out to be identifiable from structure alone, which matters because **the encyclopedia
publishes no flag for it**: `is_premium` is false for all of them, and `is_gift` was dropped back
in `2026_09_09_033544` as unused. What they do have is no lineage at all — neither a predecessor
nor a successor:

| vehicle | predecessor | ancestors |
|---|---|---|
| 113 | none | 0 |
| AMX 30 | none | 0 |
| AMX 30 B | none | 0 |
| WZ-113G FT (a real line top) | WZ-111G FT | 9 |
| Rinoceronte (a real line top) | Progetto 66 | 9 |

Nothing researches into them because they are bought outright, so they head no line and rendered
as a one-cell row with no path behind it. `lineRows()` now rejects a branch top with no
predecessor, which at tier VIII and above can only mean unreachable by research.

Five rows went, and they were exactly the five one-cell rows on the board: the 113, both AMX 30s,
the Jagdpanther II and the T-62A. Lines **72 → 67**, and the bill fell by 21,850,000 — precisely
what those five were asking for, with no knock-on effects.

Worth recording because it nearly became a false alarm: the total looked like it had dropped
46,970,000, about 25M more than the five rows cost. Measuring the change in isolation showed the
arithmetic was exact, and the gap was simply the account marking 22 vehicles as bought in the
browser between the two readings. Two measurements of live data taken at different times are not a
before-and-after.

Also this session: the Owned control became a checkbox reading "Hide lines that are fully owned",
checked by default. A tick-box can state what it does; a chip toggle leaves you to infer it from
which state looks active, which is what prompted the question. Checked now means hidden, so the
control and the flag it sets read the same way round.

Suite: **183 passed, 1023 assertions.**


## 2026-09-09 — Ticking a tank bought no longer speaks for the ones beneath it

Reported: marking the tier IX in the KPz 67 line as purchased also marked the tier VIII as
researched and bought. *"This should not be an enforced assumption past initialization. It is
possible to sell tanks, and I would like the option to indicate that where applicable."*

The row-level rule added earlier the same day — anything below a vehicle you own was owned to reach
it — was triggering on `is_purchased`, which meant it fired on a tick as readily as on play
history. Reproduced exactly: with the tier VIII (Pz.Kpfw. 55) holding no purchase record, ticking
the tier IX (Versuchspanzer 57) flipped it to bought *and* researched.

The trigger is now **play history and nothing else**. Battles in a vehicle are evidence you owned
what sits under it; a tick is a statement about one tank, and propagating it downward puts words in
your mouth about tanks you may well have sold. An explicit purchase record still wins over the
inference either way, so saying you sold something sticks.

Both directions verified against the live board: ticking the KPz 67's tier IX leaves the VIII
alone, while the Obj. 140 line's T-54 is still settled by the played tier X above it — which was
the case the rule was written for.

**"Buying a line's last vehicle settles the line" is gone as a rule**, and that is the point rather
than a casualty. It made sense while the server dropped bought-out lines and there was nothing to
click; now the lines stay and the tiers under them are yours to state. Two tests pinned it and were
rewritten to pin the replacement — one that a tick does not spread, one that play history still
does and that un-ticking survives it.

Suite: **184 passed, 1047 assertions.**


## 2026-09-10 — Active Grinding is the tech tree too, and grind targets are gone

Asked to explain the Targets list, because *"I'm not confident it is really needed. I feel it may be
a remnant of a misunderstanding of prior work."*

Half right. Not a misunderstanding — a migration that stopped one step short. The board began as a
projection of tracked targets, and four commits then rebuilt each tab on the tech tree, every one of
them moving a fact off `wot_grind_steps` and onto a tank-keyed row. `free_xp_planned` went to
`wot_tank_modules`, `blueprint_fragments` to `wot_tank_purchases`. Two never made the trip:
`banked_xp` and `is_active`. Because they stayed, the whole target apparatus stayed with them, and
so did a second copy of figures the tree already owned:

| Fact | Steps | Tech-tree boards |
| --- | --- | --- |
| Modules researched | `researched_modules` via `PATCH /steps/{step}/modules` | `wot_tank_modules` via `PATCH /research/{tank}/modules` |
| Discounted unlock XP | `research_xp_remaining` | `wot_tank_purchases.research_xp` |
| Module XP outstanding | `module_xp_remaining` | derived live |
| Credits | `price_credit`, frozen when the target was added | derived live |

Nothing synced them. Ticking a gun on Active Grinding did not move XP Remaining, and vice versa.
`activeSteps()` flat-mapped every target's steps with no dedupe, so a tank on two paths appeared
twice with two banked-XP figures — the exact thing `claimShared()` exists to prevent everywhere else.

### The reframe

**Membership of the Active Grinding list is the flag.** Given the lifecycle — add a tank, grind it
while ticking modules and typing banked XP, tick the unlock on XP Remaining when the next tank is
paid for, drop it from the list, buy the tank — there is nothing a separate "playing" tick could say
that adding and removing does not. So `is_playing` and `banked_xp` are columns on
`wot_tank_purchases`, and the target concept is gone entirely.

`GrindBoard` now builds Active Grinding **out of the XP board's own cells**. The row that claims a
tank supplies its modules; the unlocks come from every row it appears on. The two tabs cannot
disagree, because there is only one reckoning.

A consequence worth having: a tank under two tier Xs now **lists both unlocks**. Both are owed and
both are grinds you would do from that seat; the old board picked one by display order and hid the
other. `xp_required` is their sum plus modules, so the column and the total always agree. The tree
holds 50 such branch points, though this account currently owes only one branch at each.

Ticking a module still spends banked XP — that was the point of ticking them at all — but the
arithmetic moved to `updateModuleResearch()`, so it fires from either tab and only for a tank on the
list. A tank you are not playing has no balance to spend.

### Deleted

`WotGrindTarget`, `WotGrindStep`, `UpdateGrindStepRequest`, `WotGrindTargetFactory`, `ModulePicker.vue`,
`TechTree::pathTo()`, five routes, six controller methods, and **`wot:import-grind-sheet`** with its
`grind-sheet.json`. That import was a one-off and had already run; the workbook stays in `docs/`.
Two claims made earlier in this log are now false: the import note above, and the
`only: ['active', 'targets', 'totals']` convention — `targets` is not a prop any more.

`wot_grind_settings` survives, and is created by the same migration as the two dropped tables, so
that migration stays untouched in history.

### Migration

`is_playing` and `banked_xp` added, the steps folded onto their tanks — greatest banked XP per tank,
playing if any step was active — then both tables dropped. Aggregated in PHP because `max()` over a
boolean is not portable to the SQLite the tests run on.

Carried over exactly: **9 tanks, 1,001,318 XP banked**, which is the same total this log recorded
when the spreadsheet was first imported. Backup of both tables taken before the drop at
`~/grind-tables-backup-2026-09-10.sql`; `down()` restores the columns but not the rows, which were
derived rather than entered.

### Tests

`GrindingTest` lost 26 tests and its `moduleStep()` fixture, and gained 14 covering the list, the
row built from the cell, the branching unlocks, and the banked-XP arithmetic from the XP tab. The
first change made was stripping the targets and steps out of `purchaseLine()` and `branchedLines()`
and running the suite — it passed untouched, which proved the four boards had never read them and
made the rest of the deletion safe.

Suite: **235 passed, 1696 assertions.**

### Planning removed

Same day: *"Please remove the Planning section as it doesn't really make sense to me."*

Two figures lived in it. `garage_slots_vacant` was written and never read — no board, card or filter
ever asked what it said. `credits_available` had exactly one reader, the *"381.2M short"* line under
the Credits needed card, and the form was its only editor.

Both are gone, along with the shortfall line, `PATCH /grinding/settings`, `updateSettings()` and the
two columns. The card now shows what the filtered board costs and nothing else. The comparison had
been getting less useful anyway: since Tanks to Purchase started billing the whole tech tree rather
than a handful of targets, "440.9M needed against 59.8M to hand" measured a lifetime of research
against one afternoon's budget.

`wot_grind_settings` stays — the four filter columns beside these are the rest of it.

Suite: **234 passed, 1694 assertions.**

### The picker becomes a list

*"I want to change the Add a Tank You're Playing form to have nation filters, tier filters, and type
filters... Then instead of a dropdown, it should list out the tanks' (short_name) in a horizontal
list with nation flag, tier, and type all displayed."*

419 tanks in a `<select>` was a scroll, and told you nothing about any of them until you found it.
Now it is the same chip row the boards carry — nation flags, Roman tiers, the tankopedia type badges
— above a wrapped list of buttons, each wearing flag, short name, tier and type. Clicking one starts
the grind; there is no Add button and no form left to submit.

**The filters invert the boards' polarity.** Those are a view you come back to, so they hold what is
*hidden* and start with everything on. This is a list you are trying to find one tank in, where
narrowing to a nation should cost one click rather than nine — so it holds a *selection*, and empty
means all. The chips still read as what is on screen: nothing picked lights all of them, and the
first click is what starts cutting.

Two things the cells could not supply, so `options` is read from `wot_vehicles` instead: `short_name`
(the written form — "Obj. 279 (e)", not "Object 279 early") and `type`. Adding both to every XP cell
would have cost hundreds of fields to serve one list.

That change made vehicle-list ordering depend on each vehicle's own nation rather than its line's,
which the `techLine()` fixture had been leaving to the factory's random pick — so the fixture now
states one. Live: 419 tanks, all five types, all eleven nations.

Suite: **234 passed, 1702 assertions.**

### Icons shrunk, and the picker opens on the garage

Three follow-ups in one pass.

**Type badges at 80%.** They sat a shade large beside 14px text once they started appearing in
narrow chips as well as in row headings. `VehicleTypeIcon` now scales every glyph, so every use on
the page follows. **Artillery is exempt** and stays at full size, as asked: its square is the
smallest glyph of the five to start with, and a fifth off it read as a speck. The `viewBox` is untouched, so `preserveAspectRatio` keeps each glyph its own
shape however the two numbers round.

**The picker's chips start unlit.** They were lit by default, on the reasoning that a chip should
read as what is on screen. That only held while "nothing picked" meant "everything shown" — which is
the other half of this change.

**Nothing picked now opens on the garage**: tanks that are bought, still owe XP, and are not already
being ground. Live, that is **35 tanks against 419** — the tree is four hundred vehicles and almost
none of them are a real answer to "what could I start next", so it sits behind the first filter click
rather than in front of it. The heading and the count say which of the two lists you are looking at.

`options` gained `is_purchased` — unioned across lines by `AccountProgress`, like the lock on Tanks
to Purchase, because owning a tank is a fact about the tank — and `xp_remaining`, which is the same
`owed()` the Active Grinding rows total, now extracted so the picker and the table cannot disagree
about what a tank has left.

Suite: **235 passed, 1720 assertions.**

### Active Grinding on the dashboard

*"Finally add a duplicate of the Active Grinding table to the Dashboard under the News and calendar
sections."*

Not a duplicate in the source. The table moved into `ActiveGrindingTable.vue` and both pages render
the same component against the same rows, assembled by the same `GrindBoard` code — a second copy of
that markup, or of those figures, is precisely the drift that took the tracked-target tables down
earlier today.

It is **editable on the dashboard**, not a read-only glance. Banked XP is typed by hand after a
session and the dashboard is where you land, so having to leave it to record one number would be the
friction the spreadsheet never had.

Two things that took arranging:

**Reload keys differ per page.** The Grinding page keeps these rows under `active` beside four boards
that move with them; the dashboard holds the lot under `grinding`. So the component takes an `only`
prop and threads it into `EditableNumber` and `ModuleResearchPicker`, the same way
`EditableNumber` already took one — a component cannot know what the page around it calls things.

**The panel is deferred.** `GrindBoard::activeGrinding()` skips the three boards the dashboard has no
use for, but it still has to build the XP board, because every figure on a row is read off its cells:
**135ms against the 54ms everything else on the page costs** with a warm cache. It sits below the
fold, so `Inertia::defer()` paints the page first and fetches it after, behind a pulsing skeleton the
table's own height. First-paint assembly is unchanged at ~58ms. A write from the table still resolves
it, since a partial reload naming a deferred prop resolves it.

The panel sits with news and calendar because it shares their property: it reads local tables, so it
renders even when the account payloads are what failed. `sidePanels()` is `localPanels()` now, and
says so.

Suite: **236 passed, 1747 assertions.**

### Selection was never disabled — the colour was missing

*"If I click into them and hit Ctrl+A, it doesn't select the text... In fact none of the text on any
of the pages are selectable. I assume there is some sort of CSS attribute causing this."*

There was no such attribute. Text was selecting the whole time; the highlight was invisible.

`_base.scss` styled `::selection` with `var(--color-brand-100)` on `var(--color-brand-900)`, both
declared in the `@theme` block in `app.css`. **Tailwind v4 only emits the theme variables a utility
class actually uses**, and no class uses those two — so the variables were absent from the built CSS,
`var()` resolved to nothing, and the declaration was invalid at computed-value time. Background fell
back to `transparent`.

The same fault had taken out something quieter: `:focus-visible` painted its outline in
`var(--color-brand-500)`, also unemitted. Preflight removes the UA outline, so **every keyboard focus
ring on the site had been missing too**, on both bundles. Of the ten brand tokens, the build emitted
50, 200, 600 and 700 — exactly the four a utility class references.

Fixed where this project's own rule already said to put them: `_variables.scss`, which opens with
*"if only a .scss rule needs it, it belongs here."* Three SASS variables, resolved at compile time
and immune to tree-shaking. The cost is keeping them in step with the ramp in `app.css` by hand,
which is the trade that rule already accepted.

Worth remembering as a class of bug: a `@theme` token referenced only from SASS is a token that does
not exist at runtime, and it fails silently.

### Three smaller things in the same pass

**Focusing a figure selects it.** These fields hold numbers transcribed off the game's own screen —
you are always writing a new one, never amending a digit — so a click and a keystroke now replace the
value. Bound to click as well as focus, because a click's mouseup lands after the focus event and
would otherwise drop the selection; and deferred a tick, because focusing swaps the field from its
grouped display to bare digits.

**Both reset buttons are `IconRestore`.** Asked for on the XP cost; the credits one beside it does
the identical thing — put an overridden figure back to its source — and leaving one a `&times;` glyph
would have read as an oversight.

**"All modules researched"** in the module dropdown. What you know at the end of a grind is that the
vehicle is finished, not which module you finished last, and saying so was five ticks and five round
trips. One request, server-side: modules already researched are skipped, and the banked XP charged is
one subtraction of what was genuinely outstanding. `spendBankedXp()` takes XP rather than a module id
now, which is what lets a whole vehicle's worth be one adjustment.

Suite: **239 passed, 1772 assertions.**

---

## 2026-09-11 — Vanity domain `wothub.ethanbasham.xyz` → `/wot`

Redirect-only vanity domain for the WoT dashboard, so it has a shorter URL to share without
pretending it's a standalone site (the app is still single-`APP_URL`; see the "Stack
constraints" note in `CLAUDE.md` on `/wot` being mounted as an island, not a separate app).

**DNS.** Porkbun has no API access set up on this account (see prior eb-portfolio infra
notes), so the `A` record (`wothub` → `54.211.52.97`) was added manually via the Porkbun
dashboard, not by anything run here.

**nginx + cert.** New vhost `/etc/nginx/conf.d/wothub.ethanbasham.xyz.conf`, plain 301 to
`https://laravel-vilt.ethanbasham.xyz/wot` — no PHP-FPM wiring needed since it's a redirect,
not a proxy. `certbot --nginx -d wothub.ethanbasham.xyz` then added the HTTPS server block and
the Certbot-managed HTTP→HTTPS redirect on top, same pattern as `laravel-vilt`'s own vhost.

Deliberately a redirect rather than an nginx-level rewrite to serve `/wot` under the new
hostname directly: the app's `route()`/Vite/Inertia asset URLs are generated from a single
`APP_URL`, so serving the same content under a second hostname without a redirect would risk
asset links and Inertia's asset-version check pointing back at the wrong domain. A redirect
sidesteps all of that at the cost of the URL bar changing after the jump.

Verified with `curl --resolve` for both the HTTP→HTTPS hop and the HTTPS→`/wot` hop before
relying on real DNS propagation.

---

## 2026-09-11 — A favicon for `/wot` only, and a `dropins/` staging directory

**`dropins/` is now the hand-off point for binary source files** — logos, icon art, anything
that arrives from outside the repo for an agent or a human to process. Gitignored: it holds
inputs and scratch output, not project state. Whatever survives processing gets committed to
its real home under `public/`, and the original stays in `dropins/` unmodified.

### Icons are per-section, and that constrains the mechanism

A favicon is a `<link rel="icon">` in each document's `<head>`, not a site-wide setting, so
the two halves of this app can differ — and they now do. `resources/views/wot.blade.php` is
already a separate root view from `layouts/app.blade.php` (they load different Vite bundles
and never share a `<head>`), so there was nothing to untangle.

The trap is `/favicon.ico`: **browsers fetch it blind at the domain root and it cannot be
scoped to a path.** So the base site deliberately keeps declaring no icon at all and gets the
root file, while `/wot` declares its own and overrides it. Leaving the SPA is a real page load
— the "Leave dashboard" link was removed in this same pass, but navigating out by URL still
counts — so the base icon returns on its own.

`tests/Feature/Wot/FaviconTest.php` asserts both directions, because only asserting the `/wot`
half would still pass if the icon leaked site-wide.

### Why the shipped file is a 96 KB SVG

The source art is a detailed side-profile illustration with thin outlines. Resized straight to
16×16 it reads as a smudge: the linework is finer than the pixel budget, so any resampler
averages it into mush. That is a *design* problem, not a format one — Google's "G" survives
16px because it is a few bold flat regions, not because it is a vector.

Two routes were tried. A `potrace` single-colour silhouette traced from the alpha channel
(~7.5 KB) is crisp at every size but throws away the colour. A hand-made five-colour vector of
the same art keeps everything and was the one worth shipping — at 1.1 MB before treatment.

`svgo --precision=0 --multipass` took that to **96 KB (−91%)** by rounding coordinates alone,
with RMSE under 1% against the original at 16/32/48 px — visually identical where it is
actually rendered. Re-tracing from a downscaled raster got to 13–30 KB but visibly thickened
the outlines, so it was not used.

Two `potrace` gotchas worth not rediscovering, both of which silently invert the result rather
than erroring:

- **PBM stores ink, not light.** ImageMagick writes a white subject as bit 0 (paper), and
  `potrace` traces bit 1, so tracing an alpha mask without `-negate` vectorises the
  *background* — you get a filled rectangle with a tank-shaped hole.
- **Its output assumes the nonzero fill rule.** Its contours are wound so holes cancel;
  forcing `fill-rule="evenodd"` swaps solid and void.

### What shipped

`public/images/wot/favicon/`, referenced by plain absolute paths (no `asset()` — no view in
this project uses it):

| File | Role |
|---|---|
| `favicon.svg` | 96 KB, what modern browsers use |
| `favicon-32.png` | Fallback; the `type` on the SVG link is what makes older browsers skip to it |
| `apple-touch-icon.png` | 180×180, pre-composited over `--color-wot-abyss` — iOS paints transparency black, which would erase the tank's own outline |
| `icon-192.png`, `icon-512.png` | **Unreferenced.** Kept for a future web app manifest |

No `site.webmanifest` yet, deliberately. 192/512 are manifest icons, not favicons — nothing
requests them until a manifest exists, and adding one here means deciding on `scope`/
`start_url` under `/wot` for an app that sits behind auth. The files are cheap to keep and
annoying to regenerate, so they are committed unused rather than dropped.

## 2026-09-12 — Mastery badge art, mirrored from the achievements encyclopedia

The dashboard's Achievements section counted mastery badges and Marks of Excellence as bare
numbers. Wargaming publishes art for one of the two.

`GET /wot/encyclopedia/achievements/` returns 522 entries, 473 of them with an `image`. Both
counters the dashboard shows are `type: class` entries whose grades live in `options[]`:

- **`markOfMastery`** — four options, each with its own PNG (`markOfMastery1..4.png`,
  67×71). Option order is Class III, Class II, Class I, Ace Tanker, which lines up with
  `AccountDashboard::achievements()`'s `third/second/first/ace` keys.
- **`marksOnGun`** — three options, **every image field null**. There is no official MoE
  graphic on this endpoint, and nothing else in the 522 mentions "excellence". That card
  stays numeric.

Note the endpoint rejects `fields=achievement_id` with `INVALID_FIELDS` (407) — the id is the
object key, not a field. Easiest to request the whole payload.

### Mirrored, not hotlinked

Saved to `public/images/wot/achievements/mastery-{third,second,first,ace}.png`, named for the
prop key so the template interpolates the filename directly.

The source URL is
`http://api.worldoftanks.com/static/2.77.0/wot/encyclopedia/achievement/markOfMastery1.png` —
note the **`2.77.0`**. That is the game client version and it moves with every patch, so a
hotlink is a dead image on the next one. The `image` field in a fresh encyclopedia response
always carries the current version, which is where to re-fetch these from if they ever need
refreshing; there is no stable unversioned URL.

Same reasoning and same destination shape as the nation flags and vehicle-type icons already
in `public/images/`.

## 2026-09-12 — Marks of Excellence art, from tomato.gg

Follow-up to the entry above, which left the MoE card numeric because the achievements
encyclopedia publishes no art for `marksOnGun`.

Checked, in order, before settling:

- **The CDN directory the mastery badges come from.** `marksOnGun.png`, `marksOnGun{1,2,3}.png`
  and several spellings all 404. The API's null `image` fields are accurate; the files are not
  merely unlisted.
- **The mod repositories** (`spoter/spoter-mods`, `sheshiver/InsigniaOnGun`). Both read the
  marks out of the installed game client at runtime and ship no copies.

What worked: **tomato.gg** serves them at `/markIcons/mark_{1,2,3}.webp` — 24×24 WebP with
alpha, converted to PNG on the way in and saved as
`public/images/wot/achievements/moe-{one,two,three}.png`, named for the prop key like the
mastery badges.

**Provenance is worth knowing.** These are Wargaming's game-client assets rehosted by a
third-party stats site, not something tomato.gg authored and not something served by an API
with terms attached. Fine for a private single-user dashboard; swap them for original artwork
before this is ever public. There is no upstream to re-fetch from if they change — unlike the
mastery badges, where a fresh encyclopedia response always names the current URL.

Rendered at their native 24px. They are small source images and upscaling them goes soft, so
the MoE card's icons are deliberately smaller than the mastery card's 36px badges.

### Labels dropped

Both cards now show art and a count, with no text label. The name moved to `alt`/`title`, and
the image sits in the `<dt>` with the count as its `<dd>` — the badge *is* the term, so the
list stays a real description list rather than growing a redundant caption.

---

## 2026-09-12 — Crews: a fourth board, and everything the API will not tell you

A new area at `/wot/crews`, four tabs over one page the way Grinding holds five: the Crews
board itself, Recruits & Books, Battle Pass, and a Guide tab left empty until there is
something to put in it.

### What the API actually publishes about a crew

Checked against the live NA API with this project's own application ID before designing
anything, because the answer decides how much of this can ever be synced:

| Endpoint | What it gives |
|---|---|
| `encyclopedia/vehicles` → `crew` | A vehicle's seats, in order. Each carries a `member_id` naming its primary role and a `roles` map of every role that body covers. |
| `encyclopedia/crewroles` | The five roles, and the skills each can train (Commander 12, the rest 11). |
| `encyclopedia/crewskills` | 44 skills/perks — id, name, description, `is_perk`, icons. Several have null names or descriptions, and a few descriptions are unedited strings beginning with `@`. |

And what it does not publish, confirmed rather than assumed: `tanks/crew`,
`account/tankmen` and `encyclopedia/tankmen` are all `METHOD_NOT_FOUND` (404);
`account/info` carries no crew field among its 302 leaves; `tanks/stats` none among its 15
top-level keys. **There is no endpoint for a player's own tankmen, with or without an access
token.** No names, no training level, no skills learned, no banked XP.

So the composition of a cell comes from the encyclopedia and every figure written over it is
typed in by hand — the same bargain banked tank XP already strikes on the grinding boards.
`wot_vehicles` gained a `crew` JSON column and `wot:sync-vehicles` now fills it; all 1,028
vehicles carry one.

### Slots key on position, not on role

`member_id` repeats within a vehicle — the IS-7 carries two loaders — so a role cannot
identify a seat. `wot_crew_members.slot` is the position in the encyclopedia's own list, and
the role is deliberately not stored: a copy here would drift from the encyclopedia the first
time a patch moves a tank's crew around.

Seats also double up. Of 100 vehicles sampled, **70 had at least one body covering more than
one role**, and crew sizes run 2–6. The board spells one letter per body, so five letters
means five people to train; the second role appears in the tooltip and the editor only.

### What a cell says, and how

A cell is a short string of letters — C G D R L — and everything else it has to report is
carried by how they are drawn, because a tech-tree grid has room for about five characters
per tank:

| Cue | Meaning |
|---|---|
| red | no crew in the tank |
| white | a crew, none of them zero-skill |
| yellow | some zero-skill, some not |
| green | every member zero-skill |
| green panel, green text | the whole set is maxed — outranks the colour above |
| **bold letter** | that member is maxed |
| *italic set* | not well balanced |
| asterisks below a letter | zeroed XP steps on that member, 1 or 2 |
| superscript numeral | skills trained, 1–6; a crew at base 100% shows none |

The superscript was the open question — a skill-level indicator had to coexist with a colour,
a weight, a slant and a row of asterisks without becoming a fifth thing to decode. A raised
numeral after the letter is the one cue that reads as a quantity rather than as a state, and
it costs no horizontal space in a grid that has none to give. A legend under the filters
spells all nine out, which this board needs more than any other here.

### Decisions worth recording

- **Banked XP is per crew member**, not per set. A loader recruited late genuinely is behind
  the commander beside them, and the editor already opens as a modal, so five fields cost no
  more clicks than one.
- **Skill level runs 0–6**, where 0 is base 100% with nothing on top — a real state, and the
  one the progression table starts at.
- **No crew is the absence of a row**, not a row of zeroes, so the editor's "Empty the tank"
  DELETEs. Zeroed members would paint the cell as a crew that merely happens to be untrained.
- **`is_balanced` defaults to false**, so an unvouched-for crew reads as unbalanced and
  renders italic. Unknown and not-balanced are the same claim here.
- **The XP progression lives in `config/wargaming.php`**, beside `nations` — static game data
  entered by hand, which is what that file already holds. Recorded exactly as given, with no
  claim about whether each figure is the cost of that step or the running total to reach it;
  nothing computes against them yet, and the board only lists them above the grid.
- **Premiums are absent from the board**, as they are from every other board here: rows are
  research lines and a premium sits outside the tree. Their crews are real, which is why the
  Battle Pass tank picker offers every vehicle rather than the board's lines.

### Recruits, Books and the Battle Pass roster

Recruits and books are counts held against a key from config rather than a column per kind,
so a new book or a new sort of recruit is one line in `config/wargaming.php`. Books are
nations down, types across, with `universal` as a twelfth row; the two special items follow
it with a single count each in the total column. **The specials are counted in neither the
column totals nor the grand total** — a Personal Training Manual is not a booklet, a guide or
a manual, and adding it to the bottom of those columns would make the total mean nothing.

The Battle Pass tab is the one crew record that is a person rather than a tally. Nothing is
seeded: the roster is not something the API publishes and inventing one would put figures on
the page nobody recorded. `tank_id` and `crew_role` are cleared server-side when a status
moves off `in_tank`, so a tanker recalled to the barracks cannot go on naming a vehicle.

Its vehicle picker is a deferred prop — a thousand rows that only one of four tabs needs, so
the page paints without them.

### Filters, shared cells, and the one table they all use

The Crews board reuses the machinery the grinding boards already have: `TechTreeLines` for
the rows, the same claim-the-shared-vehicle rule (a tank on three lines is crewed once and
counted once), and `wot_grind_settings` for its filter row, which gained a `crews_filters`
column. The merge behind that moved out of `GrindController` and onto
`WotGrindSetting::mergeFilters()`, because two pages now save filters to one row through the
same rule. `useBoardFilters` took a `url` option for the same reason.

44 feature tests in `tests/Feature/Wot/CrewsTest.php`; suite green at 300.

---

## 2026-09-12 — A balanced crew trains as one

Follow-up to the entry above. Ticking **This crew is well balanced** in the editor now links
three of the four per-seat attributes: zero-skills, skill level and max apply to every seat
at once. Entering the same figure five times is exactly what that tick exists to save.

Banked XP stays per seat, and is the only one that does. It is the attribute that
legitimately differs across a balanced crew — a member recruited late is genuinely behind the
ones beside them — which is also why it was made per-member in the first place.

**Ticking the box does not reach back and level a mismatched crew.** It takes effect from the
next edit: the first attribute you touch is what the set snaps to. The alternative — levelling
on the tick itself — means a single click silently overwriting four rows of figures with one
seat's, and there is no obvious seat to elect as the winner.

Mechanically this is why those three controls bind `:value` / `@change` through
`setOnMembers()` rather than `v-model`. Restoring `v-model` reverts the linkage silently, and
no test would catch it: the behaviour lives entirely in the modal's working copy, the project
has no JS test runner, and the server sees only the set the modal finally posts. Noted in
`.ai/rules/crews.md` for that reason.

A line under the checkbox says which attributes it governs and that banked XP is exempt — a
control that quietly writes four other rows is a surprise otherwise.

---

## 2026-09-12 — Zeroed XP steps underline the letter instead of sitting beneath it

Reverses the asterisk row from the Crews entry above. A member's zeroed XP steps are now a
rule on the letter itself: `underline` for one, `underline decoration-double` for two, both
at `underline-offset-2` so the rule clears the baseline. None of the five letters has a
descender, so there is nothing for it to collide with.

Two things fall out of the change. The cell no longer needs a second row under the letters,
so each seat is a plain inline span again rather than a two-row flex column with a fixed
`h-2` spacer holding the baseline straight — that spacer existed only so a crew with some
asterisks and some without did not step up and down the row.

And the rule has to be worn by **the letter alone, not the seat's wrapper**:
`text-decoration` inherits, and a child cannot switch an ancestor's off. Underlining the
wrapper would drag the superscript skill level into it, producing a rule that ran on under a
number meaning something else entirely. Hence the extra `<span>` around `{{ member.letter }}`
in both branches of `CrewCell.vue` — it looks redundant and is not.

The legend gained a row: one underline and a double underline are now shown separately,
where the asterisks were one line reading "asterisks below a letter".

---

## 2026-09-12 — Crew cells spell C G D R L, whatever order the encyclopedia gives

The encyclopedia's crew order is not consistent between vehicles — an AT-1 lists commander,
driver, gunner; most tanks list commander, gunner, driver — so a column of cells reordered
itself per tank and had to be read one tank at a time. Every cell now spells its letters in
the order of `config('wargaming.crew_roles')`, which is what makes a missing radio operator
or a second loader visible as a break in a pattern rather than as something to go looking for.

`CrewBoard::members()` sorts by role rank with `slot` as the tiebreaker, so a vehicle's two
loaders keep the order the encyclopedia gave them instead of shuffling — which would undo the
point of a fixed order for exactly the vehicles that have most to say.

**The sort deliberately does not touch `slot`.** That is the encyclopedia's position and what
every stored member is keyed by, so reordering what is shown never moves what is written; the
editor still posts each seat under the slot it belongs to. There is a test for precisely that,
because the failure mode if it ever stops being true is silent: a crew's figures would migrate
between seats on save.

An unrecognised role sorts last rather than first, following the rule vehicle nations already
use — a role added by a future patch should appear after the five known ones, not displace the
commander.

---

## 2026-09-12 — Selects go back to the browser's own arrow

`@tailwindcss/forms` sets `appearance: none` on every `select` and paints its own chevron as
a background image, reserving room for it with `padding-right: 2.5rem`. Every select in this
app carries `px-2` — a *utility*, which outranks that base-layer padding — so the reservation
was wiped while the chevron stayed, and the arrow painted on top of the option text.

It had been there since the garage filters and went unnoticed because the options were words:
the chevron sat over the tail of "Medium tank" and read as part of the control. The crew
editor's zero-skills select is what exposed it — its widest option is a single digit, which
makes the box about 26px against a chevron of about 21px.

**Padding could not be the fix.** A rule in `@layer base` loses to a utility whatever it sets,
so reserving the space again would have meant `!important`, or smuggling the rule into the
utilities layer to out-specify `px-2`, or editing every call site. The first two are precisely
the cascade trap the comment above that block already warns about, and the third leaves the
next select someone writes broken again.

So `.wot select` now sets `appearance: auto` and `background-image: none`. A native control
carries its arrow inside its own intrinsic width, so the box is correct whatever padding a
component asks for and however long the options are, and with no background image there is
nothing left to overlap. The border, background and text colours set just above still apply;
what changes is that the arrow is the browser's rather than the plugin's.

Worth knowing if this ever looks wrong on a phone: Safari has historically been the least
willing to let author styles reach a natively-rendered select. If it renders light there, the
alternative is to keep `appearance: none` and reserve the space with an important padding
declaration — uglier in the cascade, identical everywhere.

---

## 2026-09-12 — `appearance: auto` on selects is reverted; the arrow gets its padding back

Reverses the entry above, same day. Handing a select back to the browser also hands it the
**popup**, which then stops honouring the `.wot select option` colours set a few lines below
it — so the closed control was fixed and the open list was wrong, which is a worse trade than
the overlap it cured.

`appearance: none` and the plugin's chevron are back, and the room for it is reserved with
`padding-inline-end: 2.25rem !important` on `.wot select`.

**The `!important` is the point, not an accident.** A normal declaration in `@layer base`
loses to `px-2` whatever it sets; an important one in a lower layer beats a normal one in a
higher, which is the single direction cascade layers run backwards. The alternative that
avoids the keyword — a rule smuggled into `@layer utilities` to out-specify `px-2` — performs
exactly the same override while hiding it where nobody would look for it.

What it costs: a select can no longer set its own inline-end padding from a utility. Nothing
needs to, and anything that did would be reopening the overlap.

2.25rem clears a 1.5em chevron sitting 0.5rem in, at both the 14px these run at and the 16px
a select inherits without `text-sm`.

---

## 2026-09-12 — Zero-skills is a three-way switch, not a dropdown

Three values, every one of them a single character, so they sit out in the open: the answer
is readable without opening anything, and setting one is a click instead of two. It is also
the control that exposed the chevron overlap two entries above, and a switch has no chevron
to overlap with.

Real radio inputs under styled labels rather than buttons wearing ARIA — grouping by `name`,
arrow-key movement between the three, and the announcement all come free from the platform.
The input is `sr-only` and never `hidden`: the latter would take it out of the tab order along
with the pixels, which is the usual way this pattern is broken. The label carries
`focus-within:ring-1` so keyboard focus is visible on the box the eye is actually on rather
than on an input nobody can see.

The other three controls in that row stay as they are. Skill level has seven values and is a
genuine list; max is a boolean; banked XP is a number.

---

## 2026-09-12 — Crews, second pass: editor, inventory and the Battle Pass roster

Iteration on the Crews area after first use. Grouped here rather than entry-by-entry.

### Crew editor

- **Seat column is the role name alone.** The single letter was redundant in a table already
  labelled by name. Secondary roles stack beneath it, one `+ Role` per line — the AT-1's
  three-job commander reads as three things rather than a sentence.
- **Columns run Seat · Zero-skills · Max · Skill level · Banked XP.**

### Recruits & Books

- **A third to recruits, two thirds to books** (`lg:grid-cols-3` + `lg:col-span-2`), and
  `items-start` so neither panel stretches to the other's height — a stretched short table
  reads as a table missing rows.
- **Boosted recruits are labelled `N-Skill Boosted Crew`.** Keys unchanged, so nothing stored
  moved.
- **The books table totals XP, not books.** Each type header shows one book's value as `(20k)`
  / `(100k)` / `(250k)`; a row's Total XP is quantity × value, worked out in the page so it
  moves the moment a cell saves. The count kept a home in the panel heading. Figures are per
  crew member, which is how a book's value is quoted. The specials remain in neither total.
  Server-side the row payload lost its precomputed total, and `totals` became per-type counts
  plus `books` and `xp`.

### The chevron detour

Recruit and book counts were briefly native number inputs with up/down chevrons, via a
`stepper` mode on `EditableNumber`. The number sat hard against the chevrons, and nothing
tried closed the gap reliably: padding lands outside the spin buttons, and a margin on
`::-webkit-inner-spin-button` produced no visible space either. **Reverted to text inputs.**

What survived is the `stepper` prop itself: bare digits rather than grouped, select-all only
on the click that focuses the field, and never disabled while a save is in flight.

Also left behind, deliberately: the `input[type='number']` rules in `AppShell`'s base layer.
They are not dead — the Dashboard's minimum-battles field is a number input and now gets the
sunken background and dark chevrons from them.

### Battle Pass roster

- **The add form is the table's first row**, so each field sits under its own column, with a
  `+` where the rows have their trash can and a dashed rule beneath. A `<form>` cannot wrap a
  `<tr>`, so the form is declared outside the table and joined by `form="…"`. The table's
  borders are set per section rather than with `divide-y`: collapsed table borders prefer
  solid over dashed, so a table-level divide would overpaint the rule.
- **A rejected add now says why**, under the name field. It used to fail silently.
- **Gender is an M / F switch**, M by default; config carries a letter beside the name,
  mirroring `crew_roles`. There is no way back to blank once set.
- **Season accepts `-` for no season**, stored as null — the roster already sorts newest
  season first with null last, which is exactly where `-` should land. The request maps a
  posted `-` to null as well. A blank and `-` are therefore indistinguishable.
- **Text inputs match the selects' height** (`px-2 py-1`).

### Choosing a tank

The In tank dropdown — a thousand-plus options — is replaced by a **Choose Tank** button that
opens `TankPicker.vue`, built after Grinding's "Add a tank you're playing": nation, tier and
type chips over a capped, scrolled list of tank chips. The vehicle prop gained `type` for it.

Two deliberate departures from that picker:

- **It opens on nothing** and asks for a filter. Grinding can offer the garage as a shortlist;
  nothing here predicts which tank a tanker will be posted to.
- **Each chip row is single-choice.** The grinding picker is for browsing, where several
  nations at once is a fair question; this one is for finding a tank you already know, so a
  second nation replaces the first. All clears a row. Rows are marked up as radio groups.

Filters persist between opens, since a season's tankers are often entered a nation at a time.
A roster row saves its choice at once; the add row only fills in the pending tanker.

---

## 2026-09-12 — Free XP board: finished vehicles are a dash, and finished lines hide

A vehicle XP Remaining has nothing left on now reads `-` on the Free XP board instead of its
module dropdown, and a line made of nothing but those is hidden by default behind a new
**Hide lines with nothing left to research** checkbox beside "Only lines I have planned on".

**"Fully researched" is XP Remaining's definition, not a new one:** every upgrade module
researched *and* every tank the vehicle leads to unlocked. `FreeXpBoard` reads both from the
same account progress XP Remaining does — the module default and the unlock state — so the
two boards cannot disagree about which vehicles are done. Only successors the tree knows about
count; a vehicle that leads nowhere is decided by its modules alone.

Deliberately not "XP Remaining owes zero". An unlock typed down to 0 XP with blueprints costs
nothing and is still not researched; state is what the board shows, and a figure could be
zero for other reasons.

**A finished vehicle's planned XP leaves every total**, on the server's `planned_xp` and
`free_xp_planned` and in the page's footer alike. A plan made before a successor was unlocked
is not cleared by the unlock, and a row total including a figure that appears nowhere above it
— the cell is a dash — would be unreadable.

**The filter defaults on**, the opposite of `only_planned` beside it: a finished line is one no
Free XP can go to, which is the same judgement XP Remaining's `hide_done` makes. It is stored
as `hide_researched` in `freexp_filters`, and a line counts as finished across every cell, not
the visible ones, so hiding a tier column never decides which lines the board has.

---

## 2026-09-12 — The finished-vehicle mark is an em dash

Follow-up to the entry above: the Free XP board marks a fully researched vehicle with `—`, not
`-`. It is the same glyph and the same `text-wot-muted` tone `ModulePlanPicker` already shows
for a vehicle with no upgrade modules at all, and both mean the same thing on this board —
nothing here to plan — so they should not be two different marks.

---

## 2026-09-12 — News toolbar alignment, and a crews legend grouped by scope

**News: the Pinned filter holds the right edge.** `ms-auto` lived only on "Mark N as seen",
so once nothing was unseen that button left the row and nothing carried the Pinned filter off
the category chips. Pinned now takes `ms-auto` itself whenever the Mark button is absent,
which leaves the spacing between the two unchanged when both are present.

**Crews: the legend is three labelled rows, broadest cue first** — Colour (the crew's
zero-skill state), Set (marks on the whole cell), Member (marks on one letter) — in the same
label column as the filter rows above it. It had been one list flowed into a three-column
grid, which split the four colours across two rows and put "whole set maxed" nowhere near
"member maxed". Maxed now leads both the Set and Member rows so the two scopes of the same
fact sit one above the other. Each row's `<dl>` is `display: contents`, so its items wrap in
the row's own flex line beside the label.

---

## 2026-09-12 — Blueprints held, per nation

A table above the Blueprints board's filters records the blueprints actually held: one count
per nation, plus the universal stack. New table `wot_blueprints` (`wot_account_id`, `nation`,
`quantity`, unique on the pair), model `WotBlueprint`, and
`PATCH /wot/grinding/blueprints/{nation}` → `GrindController::updateBlueprintStock`.

**These are not the fragments already on the board.** `wot_tank_purchases.blueprint_fragments`
counts fragments built towards one vehicle; this counts the raw material. National blueprints
and universal ones are combined to build those fragments, so the stock is keyed by nation
rather than by tank. Nothing computes fragments from it — the table records, like the cells do.

**Universal is the nation `'universal'`, not a null**, for the reason `wot_crew_books` already
gives: Postgres treats nulls as distinct in a unique index, so a nullable column would admit a
second universal row. An unknown nation in the path is a 404 rather than a validation error,
since it is a URL that does not exist.

The stock rides on the Blueprints board's own payload (`blueprints.stock`) rather than as a
sibling prop, so the `only: ['blueprints']` reload every edit on that tab already requests
brings it back. Every nation is always listed, universal last, so the page draws a fixed row.
It is one row across under a row of flags — scrolling sideways on a narrow screen rather than
wrapping, so each count stays under its own flag — with the same stepper inputs as the Crews
counts.

**Production needs `php artisan migrate`** for the new table. The deploy runs migrations, as it
did for the crew tables.

---

## 2026-09-12 — Blueprints held, redrawn

Reverses the single row described in the entry above. The stock is now a panel: each nation's
flag sits beside its own input, the eleven nations fill a six-column grid (two rows at full
width, fewer columns before anything overflows on a narrow screen), and universal takes a row
of its own beneath a divider.

The flag beside the figure means a count is read with its nation rather than found by column.
Universal is set apart because it is not a twelfth nation but the stack every nation draws on.
Each flag-and-input pair is a `<label>`, so the flag is a click target for its input and names
it for assistive tech. The data behind it — `blueprints.stock`, universal last — is unchanged;
the page splits it.

---

## 2026-09-12 — Free XP planned, over Free XP available

The Grinding page's Free XP card reads **planned / available** when the account's balance is
known, and **planned** alone when it is not. The balance is `private.free_xp` from
`account/info`, which Wargaming only returns with a valid access token.

**`AccountDashboard::freeXp()` owns the lookup**, because that class already owns the cache the
balance usually lives in:

- No valid token → null, and nothing is asked.
- A warm `wot:payloads:{id}` — the dashboard's cached fetch — is read first.
- Otherwise `account/info` is fetched **on its own** and cached as `wot:account-info:{id}` for
  the dashboard's TTL. The dashboard's full fetch is dominated by `tanks/stats`, which the
  Grinding page has no use for and should not pay for on a cold load.
- A `WargamingException` or a reply with no private block → null. Refusals are not cached.

**Null is "not known", never zero.** A card reading `90,000 / 0` would claim a balance nobody
reported; the planned-only form is the honest fallback, and the label drops "/ available" with it.

`forget()` clears both cache keys, so the dashboard's Refresh moves this figure as well.

**Test trap worth knowing:** factory accounts carry a valid-looking token, so every Grinding
render would now make a real `account/info` request. `GrindingTest` fakes it file-wide with a
closure over `$this->accountInfo`, and a test that needs a specific reply sets that property
rather than registering a second fake for the same URL — which of two overlapping fakes answers
is not something a test should depend on. `phpunit.xml` already forces an application ID and
the array cache store, so the client's guard passes and each test starts cold.

---

## 2026-09-12 — Credits available, over credits needed

The Grinding page's credits card reads **available / needed** when the balance is known and
**needed** alone when it is not — the reverse order of the Free XP card, deliberately: that one
leads with what it plans to spend, this one with what you already have. "Needed" stays the
filtered purchase-board total the card already showed.

The balance is `private.credits`, from the same `account/info` block as Free XP, so the lookup
from the previous entry was generalised rather than copied. `freeXp()` and a new `credits()`
now read one `privateBalances()`, which **remembers its answer for the life of the instance,
null included**. Without that, two figures read on a cold cache would be two fetches — and a
refusal, which is never cached, would be two failed requests. A test asserts one request for
both figures.

Each figure is null on its own when its key is missing, rather than zero, on the same "not
known is not nothing" terms as before.

---

## 2026-09-12 — Both balance cards lead with what you have

Reverses the order recorded two entries above. The Free XP card now reads **available /
planned**, matching the credits card's **available / needed**: the balance leads on both, and
the board's figure is what it is measured against. The label follows ("Free XP available /
planned"), and the planned-only fallback without a known balance is unchanged. Consistency
between the two cards was the point — they sit side by side and are read as a pair.

---

## 2026-09-12 — Headline cards: tanks researched first, banked XP over XP remaining

The Grinding page's four cards are now, in order:

1. **Tanks fully researched** — a count of vehicles on the tree with nothing left for XP
   Remaining, over the vehicles on the tree, with a percentage.
2. **Banked XP / XP remaining** — the Banked XP card folded into the XP remaining one, which
   now reads balance over what is owed, the same order as the two cards after it.
3. Free XP available / planned.
4. Credits available / needed.

**The count reads the Free XP board's `is_researched` flag rather than deriving the state
again** — "fully researched" has exactly one definition, and it lives there. Only each row's
owned cell is counted, so a vehicle on several lines is counted once, and the figure covers the
whole tree rather than following any board's filters. It is served as `totals.tanks_researched`
and `totals.tanks_total` from `GrindBoard::researchCounts()`.

---

## 2026-09-12 — Crews board filters by crew size

A **Crew** row joins the Crews board's filters: a chip per crew size that actually occurs on
the tree (2 to 6 today), lit meaning shown, like the nation and tier rows. It is only offered
when sizes differ.

**It narrows tanks, not only lines.** A tank whose size is switched off reads as an empty tier
and leaves the row, tier and grand counts; a line with no visible tank left drops off the board.
The question the filter answers is about one vehicle's crew, so filtering whole lines by a size
some of their tanks have would answer a different one.

Size is the seat count from the encyclopedia — one per body, the same count as the letters in a
cell — read from the members the board already ships, so nothing server-side changed but the
filter key. It is stored as `hidden_crew_sizes` in `crews_filters`, held as what is hidden so a
size first appearing after a patch starts shown, and validated as integers from 1 to 12.

---

## 2026-09-15 — Bookmarks strip under the World of Tanks header

A one-line row of links out to the community sites, between the header and the page on every
`/wot` screen. Ten defaults in `config/wotbookmarks.php`, shared as a `bookmarks` Inertia prop
from `HandleInertiaRequests` and rendered by `resources/js/wot/Components/BookmarkBar.vue`.

**The list is config, not a seeder, because it is about to stop being the live list.** Bookmarks
become per-user and editable next; at that point the prop keeps its name and starts resolving
off the user, and this config becomes the set a new account is seeded with. Keeping it in config
means that starting set can change later without a migration, and meant the bar could be styled
before any of the persistence existed.

**The row scrolls sideways rather than wrapping.** A wrapping bar changes height as the list
grows, so the page would shift down by a line the first time someone added an eleventh bookmark.
Its scrollbar is hidden — Firefox is the only engine that draws one in the flow, where it would
read as a second grey rule under the strip.

Every entry leaves the app, so they are plain `<a target="_blank" rel="noopener noreferrer">`
rather than Inertia `<Link>`s, and `url` is asserted absolute in the tests: a relative one would
resolve against `/wot` and 404 rather than erroring.

One note from surveying the candidates — **wot-life.com publishes AAAA records only**. It was
left out of the defaults, but if it is ever added: it is reachable from a browser while `curl`
from this machine times out with no IPv4 route, so it looks dead from the command line and
isn't.

---

## 2026-09-15 — Bookmarks became the user's own

The strip moved off config and onto a `wot_bookmarks` table, with a **Manage** button at the
right end of the bar opening a `<dialog>` editor (`BookmarkEditor.vue`).

**Keyed on `users`, not `wot_accounts`** — the one table in the sub-project that is. The bar is
up on the Connect screen, before there is a linked account to hang anything off, and nothing it
holds is game data.

**`users.wot_bookmarks_seeded_at` is the point of the design.** The config list is written out
as the user's own rows the first time their bar is read, and that column records that it
happened. Without it an empty bar is indistinguishable from a new account, so deleting the last
bookmark would hand all ten defaults back on the next page load. `BookmarkController` stamps it
on save too, for the user who empties the bar before ever being seeded. The corollary: editing
`config/wotbookmarks.php` now only affects accounts that have never loaded `/wot`.

**The editor posts the list whole and the server replaces what it holds** (`PUT /wot/bookmarks`,
`WotBookmark::replaceFor`), the same bargain the crew editor strikes. Reordering and removing are
most of what happens in there and neither expresses well as a diff; nothing addresses a bookmark
by id, so there is nothing for a reconcile to preserve. Reordering is up/down buttons rather than
dragging — no new dependency, and no keyboard trap.

**URLs validate as `url:http,https`, not `url`.** The bare rule passes `javascript:` and `data:`,
which is stored XSS as soon as one is rendered into an `href` — and this one is rendered for the
person who typed it, which is exactly what a self-XSS is aimed at. The request also prepends
`https://` to anything typed without a scheme, since nobody types one into a bookmarks field and
`url` refuses a bare host.

The shared prop is a closure so a partial reload that doesn't ask for `bookmarks` neither runs
the query nor trips the one-time seeding write behind it.

---

## 2026-09-15 — Blueprint fragments, costed: the curve is derived after all

Reverses the decision recorded on 2026-09-09 — *"Blueprint discounts are **entered, not
computed**"* — and the matching claim in `BlueprintBoard`'s docblock that the board "stays
reference only". Both sentences have been rewritten. The premise behind them was that Wargaming
publishes no fragments-to-discount curve, which is still true of the API; it is no longer true of
what is known. The curve is now `config('wargaming.blueprint_costs')`, and a Blueprints cell
reads `built / needed = XP to research` over the raw blueprints its plan would spend, opening a
planner on click.

**The economics came from the game, not from the API.** Per tier: how many blueprints one
fragment costs from the vehicle's own nation, from another nation in the same group, and in
universals; how many fragments complete the blueprint; and what share of the research XP each one
removes. Hand-transcribed, on the same footing as `crew_xp`. Confirmed while checking: the only
mission or progression table the API publishes at all is
`wot/encyclopedia/personalmissions/`, and even that is campaign 1 only.

**The 2026-09-09 workbook turned out to be the corroboration.** That entry recorded the IS-4 at
149,310 in the sheet against the API's 189,000, *"exactly ×0.79"*. The IS-4 is tier X, which the
table puts at 7% a fragment: three fragments removes 21%, and `189,000 − 39,690 = 149,310`.
`BlueprintCost` reproduces it to the XP. A figure transcribed off the game screen a week before
anyone knew it was a curve is the closest thing to an independent check available.

**The last fragment is not `percent`.** Fragments 1 to F−1 each remove their listed share; the
Fth covers whatever remains and lands the vehicle on nothing to research. Tier X is eleven at 7%
and then 23%, not twelve at 7%. It falls out of the `>= F` branch in `BlueprintCost::xpSaved()`
rather than being special-cased, and a test asserts `(F − 1) × percent` stays under 100 at every
tier so the rule cannot be transcribed into nonsense. Rounding is applied once on the cumulative
share rather than per fragment and summed, pinned by a test on a price that does not divide
evenly.

**Three planned counters, not one, because the sources are an OR.** Own-nation, the same group at
six to one, and universal are alternatives chosen per fragment, and one blueprint may mix them —
so a single "planned" figure could not be costed, the group rate being six times the national
one. New columns `blueprint_plan_own`, `blueprint_plan_group` and `blueprint_plan_universal` on
`wot_tank_purchases`, all counting fragments rather than the blueprints they are crafted from.

**Nothing is measured against the stock panel.** Planned figures are recorded and totalled, and
that is all. A group fragment eats six blueprints of *some* other nation in its group, and which
one is decided at the moment it is spent — so charging a nation for it would invent a debt that
may never be paid there. The per-nation counts stay what they were: raw material, recorded.

**`research_xp` stays, with the derived figure printed beside it.** `xp` is what a player read
off the game screen; `xp.…unlocks.blueprint_xp` is what the fragment count implies. Neither
overwrites the other and the XP board's totals still follow the typed figure — the two
disagreeing means one of them is stale, and which is not a thing the board can know. Shown only
where they differ, so an agreeing cell stays as quiet as it was. Null outside tiers II–X, so
"outside the system" reads differently from "nothing built yet".

**The cell is a button now, not an input.** Built, needed, base XP and two kinds of planned spend
is more than a grid cell has room for controls, so the cell reports and `BlueprintPlanner.vue`
writes — the bargain `CrewEditor` already struck. Unlike the crew editor it keeps no draft: every
field is an independent absolute value against `PATCH /wot/grinding/purchases/{tankId}`, which
already existed, and the planner stays open across several of them. It binds to a `tank_id` and
re-resolves the cell on each render, because every write reloads `blueprints` wholesale and a
stored cell object would show the figures the save was meant to change.

**The XP in the cell is the undiscounted price and never moves.** It is what the tank costs, so
the column can be read down for which tanks a percentage is worth most against — which is the
question the board exists to answer. What the fragments have taken off is in the planner.

**The arithmetic is on the server because there is no JS test runner.** Every figure the planner
shows arrives on `blueprints.rows.*.cells.*`, so `GrindingTest` can assert it; anything
multiplied out in a template would be arithmetic nothing checks.

**The fragment ceiling is a game rule now.** `blueprint_fragments` was `max:2000` headroom and is
now bounded by the tier's own fragment count, as are the three plan counters. The lookup that
resolves the tier sits behind `hasAny()`, so an `is_playing` toggle through the same request does
not pay for it. Two existing tests recorded 42 and 12 fragments against a tier IX, which takes
ten; both now record ten.

**Production needs `php artisan migrate`** for the three new columns.

Suite: **147 passed, 1613 assertions** on `GrindingTest`.

## 2026-09-16 — Fragments are national **and** universal: the planner replans by nation

Reverses the central claim of the 2026-09-15 entry above — *"Three planned counters, not one,
because the sources are an OR"*. They are not an OR. Every fragment of a vehicle's blueprint is
crafted from national blueprints **and** universal ones together; the only choice is which nation
pays the national half, the vehicle's own or a peer in its group at six to one. There is no
fragment bought with universal blueprints alone, and none bought without them.

**The old reading under-quoted every plan by roughly a third**, and put a "Universal" row in the
planner for a purchase that cannot happen. Confirmed with the user before any of it was changed,
along with the three questions the code could not answer: whether the table's existing `national`
and `universal` columns are the pair (they are — tier X is 4 + 12 per fragment, so a whole tier X
blueprint is 48 national and 144 universal), whether the six-to-one surcharge touches the
universal half (it does not), and which nations earn a line (the vehicle's own and its group
peers).

**`blueprint_plan`, a JSON map of nation to fragments,** replaces `blueprint_plan_own`,
`_group` and `_universal`. A column per nation was never on: which nations a vehicle may draw on
is config, and the database has no business mirroring it. `blueprint_plan_group` could not say
*which* peer would pay — the thing the planner now asks — and `blueprint_plan_universal` recorded
a purchase that does not exist, so neither could be carried across; the migration moves the
own-nation counter into the map and lets the other two go. Nothing was lost in practice: no row
on this account had a plan on it.

**One nation per request, at its own URL.** `PATCH /wot/grinding/purchases/{tankId}/blueprint-plan/{nation}`,
with the nation in the path for the reason the blueprint stock route already gives — it is part of
what is being written, not a value written against it. Each line of the planner is an independent
decision, and sending the map whole would mean every stepper click carrying a rewrite of the other
nations. A nation outside the vehicle's group 404s rather than failing validation: a blueprint
never leaves its group, so Sweden paying for a U.S. tank is a URL with no meaning.

**The modal's middle section is "Fragments Planned"** — one line per nation that could pay, own
first, reading `<counter> : <flag> <national> + <globe> <universal>`, with the per-fragment rate
and what is in that stack as a muted hint. The sentence that stood above it, *"One fragment is
crafted from any one of these, and a blueprint can mix them"*, is gone: it was the wrong model
stated out loud.

**`cost` and `group` are off the cell.** Each line carries its own `per_fragment` pair and the
lines are the group spelled out, so both were dead payload across 67 rows of nine tiers.

**The counters have − and + buttons**, via a new `buttons` prop on
`EditableNumber` that also takes a `min`/`max` to step within. This is not the chevron detour of
2026-09-14 coming back: that was the browser's own spin buttons on an `input[type=number]`, where
the digits sat hard against them because padding lands outside a `::-webkit-inner-spin-button`.
These are ordinary buttons outside the input, so the gap is just the gap between two elements. A
step is applied at once and saved after the same 400 ms pause a typed change gets, so holding a
button down sends one request; a `pending` flag keeps the reload from another edit on the page
from arriving with the old figure and undoing the click. The component's root is now a wrapper
span, `display:contents` unless there are buttons to wrap, so every other caller lays the bare
field out exactly as before.

**Production needs `php artisan migrate`.**

Suite: **374 passed, 2700 assertions.**

## 2026-09-22 — The five Vue pages, broken into components

The `resources/js/wot/Pages/` files had grown the way vibe-coded pages do: `Grinding.vue` was
1,644 lines, `Crews.vue` 990, and between them they carried the same markup several times over —
seventeen hand-written filter-chip rows in three polarities, five copies of the tech-tree table,
nine copies of `const n = …`, six of the Roman-numeral array, six native-`<dialog>` set-ups each
with its own copy of the same explanatory comment. **Pages went from 3,801 lines to 828, and the
shipped bundle from 342.6 kB to 329.8 kB** — the second figure is the real one, since the first
only says where the code moved to.

Nothing about what the app does changed. What follows is only what is worth knowing before
touching it again.

**Shared primitives now live in three places.** `lib/` holds pure functions — `format.js`
(`n`, `number`, `short`, `inK`, `roman`, the date forms), `events.js` (a calendar event's
confidence → its colour), `sale.js`, `achievements.js`. `composables/` holds state —
`useNations`, `useTableSort`, `useBoardTotals`, `useGrindBoard`. `Components/` holds markup, with
a subfolder per page for the pieces only that page uses.

**`n` and `number` are deliberately two functions.** `n` renders nothing-recorded as `0`, which is
what a count wants; `number` renders it as an em dash, which is what a measurement wants — a
vehicle with no WN8 has no expected values published for it, which is not a score of zero. Merging
them would quietly turn every unknown on the dashboard into a nought.

**One behaviour did change, and it was a bug.** The dashboard had its own ten-entry Roman numeral
array where every other copy had eleven, so a tier XI vehicle in the garage printed as a bare
"11". It now reads XI like the rest of the app. An empty purchase-board cell also prints the `·`
the other four boards print, rather than nothing.

**`useGrindBoard` is instantiated on the page, not in the board component.** Two of the headline
cards report a *filtered* board total and are on show whichever tab is up, while only one tab's
table is mounted at a time — four boards of four hundred rows is not something to render for the
sake of a card. So the state outlives the view of it. It returns a `reactive` bundle, which is
what lets the refs inside read as plain properties in a template and lets a board component write
a checkbox straight back (`v-model="board.hide_done"`), through to the ref `useBoardFilters` is
watching. That round trip is pinned by the Node check described below.

**A board's own rules are the only thing that differs between the four**, and they sit together in
`Grinding.vue` as `rules(filters)` returning `cellValue`, `keepRow` and an optional `isDone` — so
the four can be read against each other. That is how the Free XP board's
`is_maxed`-versus-`is_researched` split and the blueprint board's line-done test stay legible
as the deliberate differences they are.

**The Battle Pass add row and roster row are one component.** They were the same eight columns
written twice, which is eight chances to drift apart. `BattlePassRow` takes `draft` and every
control reports a change the same way — a patch of one field — leaving the parent to decide
whether that is a write to the server or a line in the draft. Consequence worth knowing: the
draft's fields now commit on `change` rather than on every keystroke, so `toSeason` normalises as
you leave the field instead of at submit.

**Verification, given there is still no JS test runner.** `npm run build` catches template and
import errors only; it will happily ship an identifier that does not exist at runtime — that is how
a dropped `nextTick` import in `CrewEditor` was nearly missed. So: a scripted sweep for used-but-
unimported identifiers across every `.vue`, and an eighteen-assertion Node script exercising
`useBoardTotals` and the `reactive` bundle directly (shared/bought cells excluded from totals,
hiding a tier taking its price off the bill, writes through the bundle reaching the underlying
ref). The script was run from the project root and deleted; it is worth rewriting rather than
reaching for if this area is touched again. The real gap this leaves is visual: nothing here
proves a page still *looks* right.

Suite: **342 passed, 2,619 assertions** (`tests/Feature/Wot`) — unchanged, as no PHP was touched.

---

## 2026-09-22 — Seen marks post one article at a time

**First, a correction to the record.** The 2026-09-08 entry above still describes the seen
tracker as viewport dwell — `IntersectionObserver` at 60% for 1.5s — and compares five
approaches, rejecting "hover dwell 3s" as structurally wrong. That decision was reversed on
2026-09-11 in `1892abe`, which moved to `mouseenter`/`mouseleave` at 1.5s because the viewport
tracker marked cards nobody had looked at. Per this log's own rule the reversal should have
been recorded then and wasn't, so the older entry read as current for two weeks. It stays as
written; this entry supersedes it.

**The flush was inherited, not chosen.** Marks were queued and posted every two seconds. That
earned its keep under the viewport tracker: one scroll armed a dwell timer on every visible
card simultaneously, they finished together, and a screenful left as one request instead of
twenty-four. Hover is serial — only one card is under the pointer, and `mouseleave` cancels
the dwell, so only one timer is ever live. The queue was therefore holding the single id it
had just been handed and delaying it by up to two seconds. The hover commit carried the
machinery across without re-asking the question, and three separate comments went on
justifying it by the scrolling it no longer did.

Each mark now posts as it is earned. That also closes a small hole rather than opening one:
`pending.clear()` ran *before* `router.post`, and Inertia treats a request interrupted by a
new visit as cancelled, so a flush racing a navigation took its ids down with it. The
`onBeforeUnmount` flush existed to cover exactly that window and was itself the request most
likely to be interrupted. The unmount hook now only cancels a dwell in progress, which is
correct: leaving the page is not the same as having looked at the card.

**One article per request, so the payload had nothing left to carry.**
`POST /wot/news/seen` with `{ids: [...]}` became `POST /wot/news/{article}/mark-seen`, matching
`/news/{article}/pin` beside it, and `MarkArticlesSeenRequest` is gone — route model binding
does what its rules did. The trade is deliberate: an id the client holds for a since-deleted
article was silently dropped from the batch and is now a 404. Nothing is written either way,
and a 404 is the more honest answer to a stale client. `/news/seen-all` was renamed
`/news/mark-all-seen` in the same pass, so the two writes read as the pair they are and the
path matches the `markAllSeen()` it has always reached. Nothing else about that endpoint
moved.

**The file was regrouped while this was open, and the names went with it.** `/news`,
`/calendar` and `/connect` each became a `Route::prefix(...)` group, which moved the ignore
routes from `/wot/events/{event}/ignore` to `/wot/calendar/events/{event}/ignore` — the only
*path* that changed, and `Calendar.vue` is its only caller. Names are still written out in
full on each route rather than taking a `name()` prefix from the group: a full
`wot.news.articles.pin` in the file is greppable and copies straight into `route()`, which a
prefix would cost. The four article writes are now `news.articles.*` (`mark-seen`, `pin`,
`unpin` and `mark-all-seen`) and the two event writes `calendar.events.*`, so a name says what
it acts on rather than only where it lives. `mark-all-seen` joins the `articles` set despite
taking no `{article}`: it is the same act over the whole feed. The paths were left alone —
`news.articles.pin` still lives at `/news/{article}/pin`, since nesting the URLs too would
churn every caller for no gain.

`markSeen()` still selects through `notSeenBy()` and still writes via `attachSeen()` for its
one id rather than attaching directly. Both are load-bearing: the scope is what makes the
write safe against the pivot's unique constraint and against rewriting a first-seen timestamp,
and routing the single id through the shared helper keeps one place where a seen row is
written under one rule.

**Verification.** `tests/Feature/Wot`: **340 passed, 2,616 assertions**. The batch tests became
their per-article equivalents — marking a second card leaves the first's `seen_at` alone, and
the form request's validation cases became a 404 case covering both a deleted id and a
malformed one. Two fewer tests than the last entry's 342: the three-case `validates the batch`
dataset became a two-case `404s for an article id that resolves to nothing`, and the mixed-batch
test folded into the one beside it. `npm run build` clean; the composable's remaining verification
is still by hand, there being no JS test runner.

---

## 2026-09-22 — Both seen writes became one conflict-tolerant statement

Follows the entry above, same day. `markAllSeen()` read every unseen id with `pluck()` and wrote
them back through `attach()` in chunks of 500; `markSeen()` did the same for its single id. Both
now write directly — `insertOrIgnore()` for one row, `insertOrIgnoreUsing()` as one
`INSERT ... SELECT` for the backlog — and `attachSeen()` is gone.

**The reason is not the one it looks like.** Loading the ids into PHP is the visible waste, but
at five articles a week the unseen set is small and the 500-row chunking had never once looped:
under that size `attach()` was already a single insert, so the change is two queries to one.
What actually mattered is that `pluck()` then `attach()` is check-then-act with a gap in the
middle. Two overlapping requests from the same user — a double-clicked "mark all as seen", or a
card's own mark landing mid-flight, which got likelier when marks started posting per card
rather than per two-second flush — both read the same ids, and the second insert died on
`wot_article_views`' unique index. A 500 on a button whose whole job is idempotent. The unique
index now decides per row inside one statement, so that outcome is unreachable rather than
unlikely.

`insertOrIgnoreUsing()` compiles correctly on both drivers with no branch in the code:
PostgresGrammar appends `on conflict do nothing`, SQLiteGrammar rewrites the verb to `insert or
ignore`. Verified by compiling the real statement against the pgsql grammar, and on SQLite by the
suite, which runs there and exercises it.

**`notSeenBy()` stays in the SELECT, with a changed job.** It used to be what made the write safe.
The conflict clause is that now, and `DO NOTHING` never updates, so a first-seen timestamp cannot
be rewritten. The scope remains because it keeps the inserted set to rows that are actually new.
`.ai/rules` was updated to say so — and `record-rule` filed it under `crews.md`, widening that
file's globs to claim `NewsController`; it was moved to `controllers-wot.md`, which already owns
that file, and the crews globs put back. Worth knowing the tool guesses at placement.

**The trap this leaves is silent.** The constant columns (`user_id` and three timestamps) are
bound through `selectRaw`, and those bindings land in the builder's `select` group, which is
emitted before the `where` binding `notSeenBy()` contributes. Confirmed by compiling the
statement: five bindings, `[user_id, seen_at, created_at, updated_at, user_id]`. Get that order
wrong and one column's value is written into another with no error anywhere — so the tests assert
the stored `seen_at` and `user_id`, not just that rows appeared.

**Verification.** `tests/Feature/Wot`: **343 passed, 2,630 assertions**, three tests added — the
stored values on a single mark, the stored values on every row of a mark-all, and a mark-all run
against a row that already exists. That last one is as close as this suite gets to the race:
genuine concurrency isn't reproducible here, so what is pinned is the property that makes it
safe, namely that an existing row neither errors nor changes.

---

## 2026-09-23 — The seen writes moved onto WotArticle

`NewsController` held `DB::table('wot_article_views')` twice, which is a table name at the
wrong altitude. They are now `$article->markSeenBy($user)` and
`WotArticle::markAllSeenBy($user)`, and the controller holds no table name at all — the shape
`BookmarkController` already had with `WotBookmark::replaceFor()`.

**Not a pivot model, which was the other option.** `WotArticleView::query()` would read well, but
none of the three pivot tables here — `wot_article_views`, `wot_article_pins`,
`wot_event_ignores` — has a model, and that is deliberate: they are presence-only rows, reached
through `belongsToMany` or, where a statement has to be raw, through `DB::table()` inside the
owning model. `WotArticle::scopeWithSeenFor()` and `WotEvent` both already did exactly that. A
model would also buy nothing mechanically, since a builder insert bypasses events, casts and
timestamps either way; it would be a class existing to hold a string, and this project's rules
would then want a factory for it.

The binding-order warning moved with the query, which is the point — it is a note for whoever
edits that SELECT, and it now sits next to it rather than a file away. `.ai/rules` was amended
by hand this time rather than through `record-rule`, since the rule already existed and only its
location changed; `record-rule` had filed the original under `crews.md`.

**Verification.** Full suite: **376 passed, 2,714 assertions** — unchanged, as the tests go
through the routes and never saw the difference. `npm run build` clean.

Pins followed the same day, for the same reason: `$article->pinBy($user)` and
`$article->unpinBy($user)`, with `NewsController` delegating. The write goes through the
article's own `pinnedBy()` rather than the user's `pinnedArticles()` — the write belongs to the
side the method hangs off, and `pinnedArticles()` is now purely a read path.

Worth stating because the two pairs look alike and are deliberately not: `pinBy()` uses
`syncWithoutDetaching`, which calls `updateExistingPivot` and so *refreshes* `pinned_at` on a
second pin, while `markSeenBy()` uses `insertOrIgnore` and leaves `seen_at` alone on a second
sighting. First sighting is a fact worth preserving; the moment a pin was last set is not, and
keeping it current is what makes a repeat pin safe against the unique constraint.

**The calendar moved out of NewsController the same day.** `calendar()`, `ignore()`,
`unignore()` and the four private helpers behind them (`isLongRunning()`, `days()`, `event()`,
`upcoming()`) are now `CalendarController`, which left `NewsController` at 81 lines against 214.
The two share a source — events are extracted from article bodies by `wot:sync-news` — but
nothing else: the calendar half reads `wot_events` and `wot_event_ignores` and renders a grid,
and not one of those helpers was reachable from a news route. Four imports went with them
(`Carbon`, `Collection`, `User`, `WotEvent`), which is the clearest sign they were a separate
concern sharing a file.

`resync()` stayed, even though the command it runs syncs the calendar too: it is the button on
the news page, and it redirects there.

Route names and paths are untouched — `wot.calendar`, `wot.calendar.events.ignore` and
`wot.calendar.events.unignore` just point at the new class, so no test, view or URL moved. The
`.ai/rules` globs for `controllers-wot.md` now cover both controllers.

## 2026-09-23 — Pint stops managing blank lines between methods

`class_attributes_separation` in `pint.json` no longer lists `method`. It had been
`method: none`, carried over from `eb-portfolio`, which stripped every blank line between
adjacent methods — so the only way to separate two related groups of methods was a `//`
header comment or a docblock. In practice that fought how methods here are grouped by hand
(`markAllSeen`/`markAllUnseen` beside each other, then a gap before `pin`/`unpin`), and every
`pint --dirty` run closed the gaps back up.

Omitting the key rather than setting another value is the mechanism: the `elements` map
*replaces* the fixer's defaults instead of merging with them, so an element type that isn't
listed is not processed at all. `const: one`, `property: one` and `trait_import: none` are
still enforced. Checked on a scratch class before applying — an existing blank line between
two methods survived, as did an absent one, while constants and properties were still spaced.

This departs from `eb-portfolio`, whose `pint.json` still has `method: none`. The old
`CLAUDE.md` instruction to "specify all four `elements` keys" is reversed accordingly, and
existing files aren't reformatted: their closed-up methods stay as they are until someone
regroups them.

## 2026-09-23 — The NEW badge clears on hover, not on the next load

On `/wot/news` a counted card now loses its whole NEW badge the moment the hover lands.
Previously only the square inside it went, and the badge itself stayed until the next page
load. That reverses the "badges deliberately do not clear mid-scroll" entry from the viewport
dwell tracker: under that tracker a scroll counted a screenful of cards at once, and clearing
all their badges together made the grid shimmer. The hover tracker counts one card at a time,
the one under the pointer, so that reason had lapsed — and a NEW label on a card you have
already read reads as a bug.

`ArticleCard`'s `counted` prop now gates the badge rather than the dot inside it. No server
change: `markSeen` still reloads only `unseenCount`, because `isMarked()` covers the card until
the next full load brings `is_seen` from the server. The dashboard's `NewsPanel` only ever
showed a dot, which already cleared on hover, so it is unchanged.

## 2026-09-23 — Pinning moved into useArticlePin; the seen tracker was renamed

`/wot/news` and the dashboard's `NewsPanel` each carried their own `togglePin` — the same URL
and POST-or-DELETE choice, written twice. That now lives once in
`resources/js/wot/composables/useArticlePin.js`, and both pages call it. What the composable
deliberately does *not* own is anything about a page's props: each caller passes `only` and
an `optimistic(pageProps, article, pinning)` callback, because the News page holds a paginator
at `articles.data` and the dashboard holds `news.latest` / `news.pinned`. A composable that
reached into either shape would break on the other — the same reason the pin stays an emit
from `ArticleCard` rather than living in the card.

The dashboard pin gained an optimistic update in the move: it flips `is_pinned` in both tabs
and drops the row from Pinned on an unpin. A pin doesn't add a row to Pinned — where it lands
among the other pins is the server's ordering, so the reply brings it.

`useSeenTracker` became `useArticleSeenTracker` (file and export) so the pair reads as
article-specific and leaves the generic name free. Only `News.vue` and `NewsPanel.vue` imported
it. The split is recorded as a rule in `.ai/rules/js-wot.md`.

## 2026-09-30 — pinnedFirstFor() split into withPinnedFor() + inPinnedFirstOrder()

Reverses the 2026-09-09 entry's decision to keep `pinnedFirstFor()` for the Pinned tab and
`/wot/news`. That scope did two jobs, the pins join and the hoisting sort. It's gone. Callers
now chain `withPinnedFor($user)` for the join and `inPinnedFirstOrder()` for the sort, the
same way `inDefaultOrder()` is a sort-only scope. `/wot/news` uses both. The dashboard's Pinned
tab uses `withPinnedFor($user)->inDefaultOrder()`, because hoisting pins above other pins
did nothing there.

`inPinnedFirstOrder()` requires `withPinnedFor()` with a user: it sorts on
`wot_article_pins.pinned_at is null`. It can't sort on the `pinned_at` alias instead, because
Postgres accepts an output alias in `ORDER BY` only as a bare name, not inside an expression.
If the join is missing, the query fails with a missing FROM-clause error, so the mistake shows
up immediately. The one behaviour lost is `pinnedFirstFor(null)` falling back to
`inDefaultOrder()`. Every `/wot` route requires auth, so nothing can reach that case today.

`inDefaultOrder()` now sorts on `wot_articles.published_at` / `wot_articles.id`. Before, it
used bare names, which only worked after the pins join (which also has an `id`) because
Postgres matched them against the output columns first.

## 2026-09-30 — NewsToolbar folded back into News.vue

This undoes the extraction from `af304c8`. `Components/News/NewsToolbar.vue` was only used by
`Pages/News.vue`, and everything it did belonged to that page. Its five props were the page's
own props passed straight through. It hardcoded `/wot/news`. Its `markAllSeen` optimistic
callback rewrote `articles.data`, which is the page's paginator shape, and `js-wot.md` says a
page should keep that kind of logic for itself. Moving it back removes the prop wiring. The
category and pinned-filter handlers, the optimistic callback, and the `chip` / `lit` / `unlit`
class constants now live in `News.vue`'s script.

`ArticleCard` stays a separate component, because it's the part that could be reused.

## 2026-09-30 — Resyncs reconcile events instead of replacing them

`SyncNews::store()` used to delete an article's events and re-insert them whenever the page's
hash changed. `wot_event_ignores` cascades on delete, so any edit to an article silently
dropped every user's ignores on its events, including events that hadn't changed. Future
features that attach data to events would have had the same problem.

`WotArticle::syncEvents()` now matches fresh extraction output to the article's existing rows
and updates them in place, so a matched event keeps its id. It matches in passes, and each
pass only considers rows the earlier passes left unpaired:

1. source + title + start: unchanged, though end time and metadata are still refreshed
2. source + title: rescheduled
3. source + start: renamed
4. source `window`: the article's single period, whatever changed

Within a pass, rows sharing a key pair up in date order, so a recurring session that shifts
by a day pairs first with first. Unpaired incoming rows are created and unpaired existing rows
are deleted, in one transaction, with deletes first. The command's per-article line now adds
the non-zero counts, e.g. `2 event(s): … (1 kept, 1 moved)`.

Vanished events are still **hard-deleted**, taking their ignores with them. Soft deletes,
which would restore a row if Wargaming pulls an event and later puts it back, were considered
and deferred.

A known limit: the markup gives no stable id per session, so matching can only go by what it
sees. If same-titled sessions shift onto each other's slots (Mon/Tue/Wed become Tue/Wed/Thu),
pass 1 pairs the overlapping days as exact matches, and Monday's row, with its ignores, moves to
Thursday.

## 2026-09-30 — app/helpers.php for global helper functions

Added `app/helpers.php` and registered it under `autoload.files` in `composer.json`, then ran
`composer dump-autoload`. It has no functions yet. Its header asks for each helper to be
wrapped in `function_exists()`, because Laravel and its packages define their own global
helpers, and redeclaring one of those names is a fatal error rather than an override.

No deploy or CI change was needed. Both run `composer install`, which rebuilds the autoloader
from `composer.json`. The `autoload` section isn't part of `composer.lock`'s content hash, so
the lock file didn't change.

## 2026-09-30 — carbonify(), and the calendar's end-of-month bug

`carbonify($date, $default = null)` is the first helper in `app/helpers.php`. It parses
leniently with `Carbon::parse()` inside `rescue(..., report: false)`. Empty or unparseable
input returns `$default`, which goes through `value()` so a closure default only runs when
it's used. The result is always converted to `config('app.timezone')`, because a string with
its own offset would otherwise keep it (see "Time and timezones" in CLAUDE.md). It accepts
anything `strtotime` does, relative dates included, so it's a forgiving parser, not a
validator.

`CalendarController::calendar` now reads `?month=` with it. The old
`Carbon::createFromFormat('Y-m', …)` filled in the missing day from today's date. From the
29th to the 31st, that meant asking for a shorter month rolled over into the next one:
`?month=2026-09` on 31 August showed October. `Carbon::parse('2026-09')` always gives the 1st.
`CalendarTest` now covers this, and I confirmed the test fails against the old parsing.

## 2026-09-30 — Calendar weeks run Sunday to Saturday

This reverses the Monday-first grid. `MonthGrid.vue` said it was chosen because the game's own
week and reset times run Monday to Sunday. The user wants Sunday as the first day. The grid now
starts on the Sunday on or before the 1st and ends on the Saturday on or after the last day
(`CalendarController::calendar`), and the weekday labels start with `Sun`. The labels are
hardcoded in `MonthGrid.vue` and must match where the server starts the week.
`CalendarTest` now asserts the grid's first and last dates. Before this, nothing tested where
the grid started.

## 2026-09-30 — APP_LOCALE is en_US

`APP_LOCALE` changed from `en` to `en_US` in `.env` and `.env.example`. Production's
`shared/.env` needs the same change, made by hand; see below.

This is load-bearing for the calendar. `CalendarController::calendar` calls `startOfWeek()` /
`endOfWeek()` with no argument, and without one Carbon takes the first day of the week from the
locale Laravel gives it. Under `en` that is **Monday**; under `en_US` it is Sunday, which is what
the grid and `MonthGrid.vue`'s hardcoded `Sun … Sat` labels expect. Changing the locale again
(to `en_GB`, `de`, or back to `en`) silently makes the grid Monday-first and misaligns it with
the labels. `CalendarTest`'s "whole weeks from Sunday to Saturday" test catches that.

`APP_FALLBACK_LOCALE` stays `en`. The app has no `lang/` directory, so no translation lookups
change. CI copies `.env.example`, so it picks up `en_US` too. The full suite passes: 399 tests.

**Production:** Claude Code's permission checks blocked access to the server, so the user
applies this by hand: set `APP_LOCALE=en_US` in `/projects/laravel-vilt/shared/.env`, then
rebuild the config cache in the current release so the running app sees it.

## 2026-09-30 — App\Models\Model, a base class for model helpers

Added `app/Models/Model.php`, an empty `abstract class Model` that extends Eloquent's `Model`,
as the place for helpers shared across models. All 19 models in `App\Models` now extend it.
Since it has the same short name, each model just dropped its
`use Illuminate\Database\Eloquent\Model;` line, and `extends Model` now resolves to the app's
own class in the same namespace. Leaving that import in a model would quietly bypass the base
class, so there's a test for it.

`User` is the exception, because it has to extend `Authenticatable`. A helper `User` also
needs should go in a trait used by both the base class and `User`.

`tests/Unit/ArchTest.php` (the first file in `tests/Unit`) is a Pest `arch()` test requiring
every class in `App\Models` to extend `App\Models\Model`, ignoring the base class itself and
`User`. I confirmed it fails when a model extends Eloquent's class directly.

`php artisan make:model` still generates models that extend Eloquent's class. The arch test
flags them. Changing the generated code would mean `php artisan stub:publish`, which creates a
new top-level `stubs/` directory, so that is left for the user to decide.

## 2026-09-30 — Date-range scopes on App\Models\Model

The first shared helpers on the base model are three scopes. They are reworked from a draft the
user brought, which had four problems:
- With both bounds, the draft's between-scope silently dropped `NULL` rows. With one bound it
  included them.
- A bound `carbonify()` couldn't read became "no bound", which widens the filter.
- It included `NULL` rows by default.
- Its names sat close to Laravel's own `whereFuture()` / `whereDate()` / `whereBetween()`.

- `onlyOnOrAfter($column, $date = null, $orNull = false)` and `onlyOnOrBefore(...)` compare to
  the second and default to now.
- `onlyWithinDays($column, $from = null, $to = null, $orNull = false)` rounds `$from` to the
  start of its day and `$to` to the end of its day, in app time. A null end is open, and with
  neither bound the query is returned untouched.
- `$orNull` adds rows with no value in every case, including when both bounds are given.
- A bound that is present but unreadable throws `InvalidArgumentException` instead of being
  dropped. Only an actual `null` means open-ended.
- The column is passed through `qualifyColumn()`, so the scopes work under a join.
  `WotArticle::withPinnedFor()` is the case in this app, since it adds a second `created_at`.

Nothing in the app uses them yet. `WotEvent::scopeOnlyBetween()` tests whether an event's span
overlaps a range, and `scopeOnlyUpcoming()` ORs two columns, so neither is one column against a
range. Tests are in `tests/Feature/Models/ModelTest.php`. I confirmed the join test and the
closed-range `orNull` test fail when those behaviours are removed.

## 2026-09-30 — onlyOverlappingDays(), and WotEvent::scopeOnlyBetween() built on it

`App\Models\Model::scopeOnlyOverlappingDays($startColumn, $endColumn, $from, $to, $openEnded = false)`
keeps rows whose span overlaps a range of days. It uses the standard two-comparison test: the row
starts before the range ends, and ends after the range starts. That covers starting inside,
ending inside and covering the whole range. The user's draft used three OR'd branches over the
earlier draft helpers, which it relied on for the same result.

Its conventions match `onlyWithinDays()`: whole days in app time, a null bound leaves that end
open, an unreadable bound throws, and both columns are qualified with the table name.

**A missing end is treated as a single moment by default**, and the row's start stands in for
its end. The draft treated it as still running forever. That is wrong for `WotEvent`, where a
missing `ends_at` means a session with no listed end time. Under the draft, a past session would
have shown up in every later calendar month. `openEnded: true` gives the still-running meaning
for data that needs it.

`WotEvent::scopeOnlyBetween()` is now a one-line call to it. Its two callers already pass
day-aligned bounds, so rounding to whole days doesn't change their results: the calendar grid
runs from midnight on its first Sunday to the end of its last Saturday, and the dashboard from
today to the end of the fifth day. The calendar and dashboard tests pass unchanged.

The one-moment default is tested only in `ModelTest`. With it broken, no calendar or dashboard
test fails, because none checks that a past session with no end stays out of later months.

## 2026-09-30 — The calendar grid moved into CalendarBoard

The user doesn't want private methods on controllers. Complexity should sit in the action itself
or in a service. `CalendarController`'s `days()` and `event()` are gone, and the month grid is
now `App\Services\WotNews\CalendarBoard::for($month, $user)`. It follows the `*Board` convention
in `app/Services/Wargaming`: a class whose `for()` returns page props, injected into the action.
It returns `days` and `ongoing`. The controller keeps the parts specific to the request: reading
`?month=`, the month labels, and the "Coming up" closure.

The check for whether an event falls on a given day was written out three times: twice in
`days()` and once in `DashboardController::upcoming()`. It is now `WotEvent::occursOn($day)`. A
missing end means a single moment, matching `onlyOverlappingDays()`.

`CalendarBoard` was added to `controllers-wot.md`'s paths, since it now does the per-user reading
that rule covers. `DashboardController`, `CrewController` and `GrindController` still have
private methods; only the calendar was changed.

## 2026-10-01 — Financial Fleet sub-project (/finance), first pass

A second Inertia + Vue island, built in one unattended session as a design prototype: the user
asked for as much as possible to look at, written so the whole thing can be thrown away. Nothing
here is committed.

**What it is.** A "fleet" of assets and liabilities (`fin_holdings`), optionally itemised into
positions, with income streams and expenses (`fin_flows`) that stand alone or hang off a holding.
Tools read the fleet: Overview, Monthly Budget, Savings Goals, Portfolio Projector, Real Estate
Comparator, Retirement Strategizer (Roth conversions before RMDs), Projected vs Reality, and four
calculators. Settings has a "Load the sample fleet" button, which is the quickest way to see it.

### Decisions worth knowing

- **Its own middleware, not a branch in `HandleInertiaRequests`.** That one renders into `wot` and
  shares World of Tanks props. `HandleFinanceInertiaRequests` has root view `finance` and shares
  only `auth`, `flash` and the config lists the forms pick from.
- **`config/inertia.php` had to learn about it.** `pages.paths` listed only `js/wot/Pages`, and
  `ensure_pages_exist` is on, so every `assertInertia()->component()` on a finance page failed
  until `js/finance/Pages` was added. A third island will need the same line.
- **All arithmetic is in PHP** (`app/Services/Finance`), none in Vue. There is no JS test runner,
  so a figure computed in a template is one nothing checks. The tools are GETs that render from
  the query string; `useToolQuery` debounces the form and revisits with `preserveState`, so
  typing recalculates without losing focus. Chart geometry is the only maths in the JS.
- **Config, not enums**, for holding types, flow categories, frequencies and the tax tables
  (`config/finance.php`). The same arrays feed the validation rules and, through shared props, the
  selects, and there was no `app/Enums` to put enums in.
- **Models are in `App\Models\Finance`, tables are `fin_*`, and `User` is untouched.** Ownership
  is a `scopeOnlyOwnedBy($user)` on `OwnedModel` rather than relationships on `User`, so no shared
  file references the sub-project. `tests/Unit/ArchTest.php` still passes: everything extends
  `App\Models\Model`.
- **One migration for all seven tables**, so one rollback removes them.
- **No chart library** — dependencies were not to change without approval. `LineChart.vue` and
  `DonutChart.vue` are hand-drawn SVG. The six series colours were run through the dataviz
  palette validator (lightness band, chroma floor, colour-blind separation, 3:1 contrast); the
  brand navy and gold fail as marks, so the chart blue and gold are mid-tones of them.
- **Tax figures are tax year 2026** (Rev. Proc. 2025-32). Single and married-joint were checked
  against the IRS release. **Head-of-household's inner bracket thresholds were typed from memory
  and not verified** — check them before relying on that filing status.
- **`updateOrCreate` cannot match a `date` column by string.** SQLite stores a time on it, so the
  match misses and the insert trips the unique index. Snapshots and budget actuals look the row
  up with `whereDate` instead. Two tests caught this; it would not have shown on Postgres.
- **Run rate excludes flows that have not started.** `Flow::current_monthly_amount` is what the
  cash-flow totals use; `monthly_amount` would credit a household with a pension thirteen years
  before it starts.

### Known simplifications

The Retirement Strategizer is a comparison model, not a forecast: one growth rate, no tax on
growth in the taxable bucket, Social Security taxed at a flat 85%, no state tax, IRMAA or Roth
five-year rules. The projector does not redirect a payment once its debt clears. The base site
has no link to `/finance` yet. None of the pages has been laid out for a phone.

### Verified

153 tests in `tests/Feature/Finance` (580 in the whole suite, all passing). Every page was also
loaded in headless Chromium against a throwaway SQLite database, with the sample fleet and with an
empty one: no console errors, and the dialogs, tool recalculation and budget entry were driven by
hand. The dev database had the migration run against it and nothing else — no rows were written.

### Removing it

```
php artisan migrate:rollback --step=1        # only while this is still the last migration;
                                             # otherwise drop the seven fin_* tables by hand
rm -r app/Models/Finance app/Services/Finance app/Http/Controllers/Finance \
      app/Http/Requests/Finance database/factories/Finance tests/Feature/Finance \
      resources/js/finance
rm app/Http/Middleware/HandleFinanceInertiaRequests.php routes/finance.php \
   config/finance.php resources/views/finance.blade.php \
   database/migrations/2026_10_01_175646_create_fin_tables.php
```

Then four edits to shared files, each a single marked block:

- `routes/web.php` — the `/finance` route group and its `use` line.
- `vite.config.js` — the `resources/js/finance/app.js` input.
- `config/inertia.php` — the `js/finance/Pages` path.
- `resources/css/app.css` — the `--color-fin-*` tokens, between the "begin" and "end" markers.

## 2026-10-01 — Finance: one Retirement type, and compound accounts

The user's first refinement of the fleet's data structure, in a second migration
(`2026_10_01_211519_restructure_retirement_holdings`).

**Retirement accounts are one type with two facts.** `traditional` and `roth` were holding types,
which left nowhere to record the plan. `type` is now `retirement`, with `plan_type` (`ira`,
`sep_simple_ira`, `401k`, `403b`, `457b`) and `tax_type` (`traditional`, `roth`). Both lists are in
`config/finance.php`. Nothing reads the plan yet beyond the label; it is there for the rules that
differ by plan. `Holding::tax_treatment` now comes from `tax_type` for a retirement account, and
`type_label` reads "Roth IRA" or "Traditional 401(k)". The request clears both columns on any
other type, so a holding edited out of being a retirement account stops being sorted as one. HSA
was left as its own type.

The migration converts existing rows and has to guess the plan: a name containing 401, 403 or 457
gets that plan, anything else becomes an IRA. The dev database held no retirement rows, so nothing
was guessed there.

**Compound accounts are `parent_id`, one level deep.** A retirement account can hold other
holdings — brokerage, savings, cash or crypto, the `holds` list on the type in config. With
children, the parent's value is their sum, its rate their value-weighted blend and its contribution
their total; its own three figures are ignored and the edit form hides them. A child is taxed as
its parent is. Making another type compound is giving it a `holds` list.

- **Two reads of the fleet.** `Fleet::holdings()` is top-level only and is what lists and totals
  use. `Fleet::leaves()` swaps each compound holding for its children and is what the projector and
  the Retirement Strategizer use, since growing a balance at a rate only means something for a
  leaf. Totalling a list that included children would count their money twice.
- **Depth is enforced in the form request, not the schema.** A parent must be the user's own,
  top-level, and of a type that holds the child's type; a holding with children cannot be moved
  inside another or changed to a type that holds nothing.
- Deleting a parent cascades to its children. Rolling the migration back drops `parent_id`, which
  lifts children out to stand alone rather than deleting them.

176 tests in `tests/Feature/Finance`, the new ones in `RetirementAccountTest.php`. Also driven in
headless Chromium: adding a 403(b), adding an account inside the sample Roth IRA, and the
projector and strategizer reading the nested accounts.

Removal now needs `migrate:rollback --step=2` and the second migration file deleted.

## 2026-10-01 — Finance: state and local tax brackets

A third migration (`2026_10_01_215149_add_state_and_local_tax_to_fin_profiles`) puts state and
local income tax on the profile: `state`, `state_deduction`, `state_brackets`, `local_name`,
`local_deduction`, `local_brackets`. Brackets are JSON lists of `{rate, up_to}`, with `up_to` null
on the open top bracket.

- **Presets fill the form; the profile is what counts.** The user asked for Tennessee and New
  York to auto-populate but stay editable. `config('finance.tax.states')` holds a deduction and a
  bracket table per filing status for each, and picking a state on the settings page copies them
  into editable fields. Nothing is looked up from config again after that: the tools tax with the
  saved copy. So editing config changes what the form offers next, and reaches nobody who has
  already saved. This is the opposite of the federal table, which is read from config for everyone.
- **Tennessee** is an empty bracket list — no tax on wages or retirement income.
  **New York** is tax year 2026, the first step of the FY2026 budget's rate cut. Single and joint
  thresholds were checked against published tables; **head-of-household thresholds were not**, and
  one published source still showed pre-cut rates for joint filers, so the figures want checking
  against the state's own instructions. The high-income recapture is not modelled.
- **New York City** is included as a local preset (`localities.nyc`), since "state and local" for
  New York means little without it. Any other locality is typed in by hand.
- **The tools now use it.** `TaxCalculator::totalTax()` is federal plus `stateAndLocalTax()`, and
  the Retirement Strategizer taxes with it. The strategies still fill *federal* brackets. A profile
  with no brackets behaves exactly as before, which is why the existing planner tests did not move.
- **Known crudeness:** state tax is applied to the same income as federal, less the state's own
  deduction. No state-specific exclusions — New York's exemption of Social Security and of the
  first $20,000 of pension and IRA income is not modelled — so it will tend to overstate for a
  retiree. The Retirement page says so.
- Bracket order is validated in the request's `after()`: each ceiling above the last, and only
  the final bracket open-ended, because `TaxCalculator::progressive()` walks the list trusting both.

187 tests in `tests/Feature/Finance`, the new ones in `StateTaxTest.php`. Removal is now
`migrate:rollback --step=3` and three migration files.

## 2026-10-01 — Finance: a goal for the Retirement Strategizer

The strategizer recommended whichever strategy left the most after tax. The user asked to choose
what "best" means: Maximum Account Balance, Minimum Tax Burden or Minimum RMD. It is a `?goal=`
query parameter (`balance`, `tax`, `rmd`), defined in `RothConversionPlanner::GOALS` with the
summary figure each ranks by.

- **"Maximum account balance" ranks by after-tax wealth, not the raw total.** A traditional dollar
  still has tax owed on it, so adding the buckets at face value would put "No conversions" first
  nearly every time just for deferring the bill. The raw total is shown as its own row
  (`ending_balance`). This is a reading of the user's wording, flagged to them — switching it is
  changing one `metric` key.
- **"Minimum RMD" ranks by lifetime RMDs in total** (`total_rmd`, new), not the first or the
  largest. Both of those are still in the table.
- **A strategy that runs out of money cannot win**, whatever its figure — otherwise the least tax
  is paid by the plan that goes broke. Ties on the goal's metric go to the higher after-tax
  wealth, which is the strategy that converted less to get the same result.
- `advantage` is now measured on the goal's own metric, always positive when the best strategy
  beats not converting.

No schema change. 196 tests in `tests/Feature/Finance`.

## 2026-10-01 — Finance: two adaptive strategies for the Retirement Strategizer

The three fixed strategies fill the 12%, 22% or 24% bracket every year whatever the income, so
someone already in a higher bracket got nothing from any of them. The user asked for both fixes.

- **Fill your current bracket** (`fill_current`). Each year, convert up to the top of whichever
  federal bracket that year's income and RMD already sit in. It never raises the marginal rate.
  Income inside the standard deduction fills the deduction (a conversion at no tax); income in
  the open top bracket converts nothing, as there is no top to fill to.
- **Optimised year by year** (`optimised`). A local search: start from the best fixed strategy's
  plan, then for each year of the window try converting nothing or filling to the deduction, 10,
  12, 22, 24 or 32%, keeping whatever ranks better by the chosen goal, and repeat for up to three
  rounds. It cannot do worse than a fixed strategy, because it starts from the best one and only
  accepts improvements. It is not a proof of the optimum — it stops at a plan no single-year
  change improves. An exhaustive search is seven choices to the power of the window length.
- **`simulate()` now takes a plan**, age => bracket to fill, instead of one ceiling. A fixed
  strategy is the same entry for every year. Rows carry `filled_rate`, shown beside each
  conversion in the year-by-year table.
- **It had to be made fast.** The optimiser runs the lifetime a few hundred times, and the first
  version took 0.7 to 4 seconds a request, on a page that recalculates as you type. Each year's
  income (a sum over every flow), the profile's accessors, the RMD divisors and the tax tables
  are now read once in `for()` and handed to `simulate()`; `TaxCalculator::totalTaxFor($profile)`
  returns a closure over the decoded state brackets, since reading a JSON-cast attribute decodes
  it on every access. Now 60 to 100 ms on the sample fleet.
- **Known wrinkle:** a year can show "to 32%" while its Bracket column reads 35%. The plan sizes
  the conversion to the bracket; if taxable savings cannot cover the tax, the rest is drawn from
  traditional money, which is income too and can spill over the line.

207 tests in `tests/Feature/Finance`. Strategies are appended, not inserted, so the chart colours
of the original four did not move; all six palette slots are now in use, so a seventh strategy
would need a different way of telling them apart.

## 2026-10-01 — Finance: the fixed-bracket strategies are no longer offered

The user did not want "Fill the 12% / 22% / 24% bracket" as strategies. The Retirement
Strategizer now shows three: No conversions, Fill your current bracket, Optimised year by year.

The three fixed plans are still run, as **starting points for the optimiser** (`SEED_CEILINGS`),
alongside the two plain strategies. A local search only finds the best plan near where it starts,
and dropping these seeds would have made the optimised plan worse for a reason invisible on the
page. They are simulated for their summaries only and never returned.

Chart colours now run green, blue, gold for the three. Tests that exercised the mechanics through
`fill_12` and `fill_24` were rewritten against `fill_current`; still 207 in `tests/Feature/Finance`.

## 2026-10-01 — Finance: the tax left to heirs is counted and shown

The user's point: converting only "wins" on balance if the tax on unspent traditional money is
counted, and the report hid that. Checking it against the code, half of it was already handled
and half was a real gap.

- **Already handled, but invisible.** The balance goal ranked by `after_tax_wealth`, which takes
  the heirs' tax off the traditional balance. Nothing on the page said so beyond a row called
  "Balance after tax owed" and an input among the assumptions.
- **The gap.** The tax goal ranked by `lifetime_tax` — tax paid while alive. Converting nothing
  scored best on it partly by leaving the largest untaxed balance behind: a deferred bill counted
  as no bill.

What changed: the summary now has `heir_tax` (ending traditional balance times the heirs' rate)
and `total_tax` (lifetime plus heirs'). The tax goal ranks by `total_tax`. The table shows "Tax you
pay", "Tax left for your heirs, at N%" and "Total tax, yours and theirs" as three rows, the verdict
quotes the heirs' tax for the recommended plan and for not converting, and a callout under the
table states the point outright. Setting the heirs' rate to 0 reproduces the old figures.

The heirs' rate is one flat number (default 22%). Real inherited-IRA tax depends on the heir's own
bracket and the ten-year withdrawal rule; none of that is modelled. 210 tests in
`tests/Feature/Finance`.

## 2026-10-02 — Finance: settings page shows IRMAA tiers; state and local tax moves under federal

The settings page was rearranged at the user's request. The state and local card moved out of
the left column to sit under the federal brackets on the right, and now reads as a static table
in the federal card's design until **Edit** is pressed, which swaps in the same editor as before.
Its old place on the left holds a new, read-only IRMAA card.

- **Still one `useForm`.** The state card has its own Save, but both Save buttons send the whole
  profile — `SaveProfileRequest` requires every field, so splitting the form would have meant
  splitting the request. Cancel resets only the six state and local fields. A validation error
  on any of them reopens the editor so the refused row is on show.
- **The static table reads `profile`, not the form**: it shows what the tools are taxing with.
- **IRMAA is `config('finance.irmaa')`**, 2026 tiers as `[MAGI ceiling, Part B / month, Part D
  surcharge / month]` per filing status, sent to the page as the `irmaa` prop. **Display only** —
  `TaxCalculator` and the Retirement Strategizer still ignore IRMAA. The standard premium
  ($202.90), the first and last thresholds and the ends of both premium ranges were checked
  against published 2026 tables; the three inner tiers were not (noted in the config comment).

## 2026-10-02 — Finance: Projections & scenarios

New area at `/finance/scenarios` (rail link under Income & expenses, and a button on that page).
A scenario is a named set of assumptions about how each income and expense moves from this year
to the profile's plan-to age. The list page compares scenarios; a scenario's page lists every
flow as a row that opens onto a rate field and a year-by-year chart with a slider per year.

- **A scenario copies nothing.** `fin_scenarios` holds the name; `fin_scenario_flows` holds only
  what a scenario changes about one flow: `annual_growth_rate` (null = the flow's own) and
  `overrides`, a JSON map of year → amount. A flow with no row is projected as it stands, so
  editing a flow on Income & expenses edits it in every scenario, and a new flow appears in all
  of them. A row left with no rate and no overrides is deleted rather than kept empty.
- **Rate first, then pins.** `ScenarioBoard` gets each year from `Flow::amountInYear()` (which
  gained an optional `$growthRate` argument), so start/end dates and the stop at retirement still
  apply under a scenario. A pinned year then replaces that year only — it does **not** re-base
  the years after it. That was the simple, predictable choice; "carry a pin forward" was
  considered and left out because it tangles with part-year and retirement handling.
- **Nominal dollars**, and the current year counts as a full year, as `amountInYear()` already did.
- **The arithmetic stays in PHP.** The chart computes geometry only. A dragged point is itself the
  figure (the pinned amount) and is sent as-is; the rate-driven line and all totals come back from
  the server. Each change is a `PUT` of the flow's whole settings, with no flash message.
- **`ScenarioFlowRow` keeps a local draft** of the rate and pins and sends that, not the props:
  two quick drags would otherwise have the second save drop the first pin, because the props have
  not caught up yet.
- **Trap found in the browser:** pressing on one slider blurs the previously focused one just
  after `pointerdown`, and a blur handler that commits unconditionally ends the new drag before it
  moves. `YearSliderChart`'s `onBlur` only settles its own keyboard nudge, never a drag.
- Rates are limited to ±50%, matching `SaveFlowRequest`. "Clear everything" in Settings now
  removes scenarios too. A scenario can be copied, carrying its rates and pins.
- **Not connected to the other tools yet**: the budget, overview and Retirement Strategizer still
  read flows as entered, not through a scenario.

Checked by driving the pages in headless Chromium against a throwaway SQLite copy (never the dev
database): create, apply a rate to all incomes, drag, rapid consecutive drags, keyboard nudges,
reload. Tests are in `tests/Feature/Finance/ScenarioTest.php`.

## 2026-10-02 — Finance: the scenario list leads with the comparison, and draws each plan inline

At the user's request the list's lifetime income / expenses / left-over columns went. In their
place: "Against nothing adjusted" first, then left over a year as `average [leanest (yr) — best
(yr)]` — each figure ink when positive, bold red when negative — then an inline sparkline.

- `ScenarioBoard::leftOver()` supplies the average, min and max with their years (the earlier year
  on a tie; null when there are no flows). `summary` is still sent but the list no longer reads it.
- `CashflowSparkline` draws expenses in red and income green above them / grey below, with a red
  wash in the gap. It does not compute where the lines cross: the income line and the band between
  the lines are each drawn twice through two clip paths (everything above the expense line,
  everything below it), so the split falls out of the clipping.

## 2026-10-02 — Finance: the scenario list's left over leads with the total, not the average

Reverses part of the entry above. The user preferred the whole-plan total to the yearly average:
a negative total is the burden the retirement accounts would have to carry over the life of the
projection, which an average hides. `left_over` is now `total [min (yr) — max (yr)]`; min and max
are still single years.

## 2026-10-02 — Finance: zero bands and crossing marks on the scenarios chart

`LineChart` gained an opt-in `zeroBands` prop, used only by "What is left each year" on
`/finance/scenarios`: the plot is washed green above zero and red below, and a hollow ring is
drawn where each line crosses zero (its tooltip names the first year on the far side). Off by
default, so every other chart is unchanged. The crossing position is interpolated along the drawn
segment — geometry, like the rest of the chart, not a financial figure.

## 2026-10-02 — Finance: scenario table figures abbreviated; ages on the projection charts

- The scenarios table prints three significant figures (`$150K`, `$1.57M`) through new
  `moneyBrief` / `moneyBriefSigned` formatters in `lib/format.js`; the exact amount is the cell's
  `title`. `moneyShort` was left alone — it rounds to one decimal and other pages depend on that.
- Year labels on the three projection charts (the list's, a scenario's "Year by year", and each
  flow's slider chart) read `2040 (66)`: year, then the age reached that year, from the `age`
  already in each total. The line charts dropped from 8 x-labels to 6 to make room.

## 2026-10-02 — Finance: incomes are taxed, on the cashflow page and in every projection

The user's point: nothing on Income & expenses or in Projections & scenarios took tax off. Asked
for, and kept deliberately blunt: four tax treatments per income, and settings for the standard
deduction, the self-employment tax rate and the long-term capital gains brackets.

- **`fin_flows.taxation` replaces `is_taxable`.** Null is "not taxed"; otherwise one of
  `config('finance.flow_taxations')`: `w2` (income tax + half the SE rate, as FICA),
  `self_employed` (income tax + the whole SE rate), `income_only` (S-corp profit, pensions, rent),
  `capital_gains` (the LTCG brackets). The migration backfilled existing taxable incomes from
  their category — salary → w2, contract → self_employed, everything else → income_only — which
  is also the default the form offers for a new income (`flow_categories.income.*.taxation`).
  Business income defaults to `income_only` because the user described it as S-corp profit.
- **Three new profile columns.** `standard_deduction` and `ltcg_brackets` are nullable, and null
  means "the built-in figure for the filing status", so they keep following a change of filing
  status until someone pins their own. `se_tax_rate` defaults to 15.3.
- **`TaxCalculator::flowTaxFor()` is the one place the rules live.** Ordinary income less the
  deduction through the federal brackets; capital gains stacked on top through the LTCG brackets,
  after whatever deduction ordinary income left unused; payroll tax flat on the whole amount;
  state and local tax on ordinary + gains. **Not modelled:** the Social Security wage base, the
  92.35% SE adjustment and the half-SE deduction, additional Medicare tax, NIIT, QBI, credits.
- **`TaxCalculator::forProfile()`** returns a clone carrying the profile's own deduction, for the
  methods that are only told a filing status. `RothConversionPlanner` swaps its calculator for
  that clone at the top of `for()`, so the Retirement Strategizer honours a custom deduction too.
  It still taxes everything as ordinary income — payroll tax and the LTCG brackets are **not** in
  the strategizer.
- **Where the tax shows.** `Fleet::cashflow()` takes the monthly tax
  (`TaxCalculator::monthlyRunRateTax()`: the current run rate annualised, taxed, divided by 12)
  and reports `taxes`, `tax_rate`, and an after-tax `net` and savings rate — on Income & expenses
  and the Overview. `ScenarioBoard` taxes each projected year as a whole, on tables indexed by the
  profile's inflation rate, and adds `taxes` and `take_home` to every year; `net`, "left over",
  the comparison against the baseline and the list's sparkline are all after tax. A flow's own
  row stays gross — tax belongs to the year's income together, not to one flow.
- The 2026 LTCG thresholds in config were typed from memory and are flagged as unchecked there.
- Existing tests whose totals moved were either given the new figure with the arithmetic in a
  comment, or had their income marked untaxed where tax was not the point of the test.

## 2026-10-02 — Finance: each income has a taxed portion

`fin_flows.taxed_portion` (percent, default 100) sits beside "Taxed as" on the income form, for
cases like Social Security where only part of an income is taxable. It replaces the fixed
`'taxable' => 0.85` that config applied to every Social Security income, which is gone:
`Flow::taxable_share` is now `taxed_portion / 100` for any income with a treatment.

- **Existing rows were left at 100**, including Social Security, at the user's direction — the
  real share depends on the household's other income, so it is a setting they own. This raises
  the taxable income the Retirement Strategizer sees for an existing Social Security flow from
  85% to 100% until its portion is set.
- The sample fleet's Social Security is created at 85 so the sample behaves as before.
- The field only shows when the income has a tax treatment; an expense carries the default.

## 2026-10-02 — Finance: a scenario sets how fast its tax tables rise

`fin_scenarios.bracket_inflation_rate` (nullable, −5 to 15): one flat yearly rate applied to every
bracket threshold and the standard deduction — federal, capital gains, state and local — when a
scenario's years are taxed. Null follows the profile's inflation rate, which is what projections
used before. Set from the "Year by year" card on a scenario's page; copied with the scenario.

- It only moves the tax tables. Incomes and expenses still grow at their own rates.
- **The baseline ("nothing adjusted") always uses the profile's rate**, so a scenario's own rate
  shows up in "against nothing adjusted" like any other adjustment.
- The control saves through the existing `PATCH /scenarios/{scenario}`, sending the name and
  description along. The request treats the rate as optional, so the list page's rename dialog,
  which does not send it, leaves it alone; an explicit null hands it back to the profile.

## 2026-10-02 — Finance: the Retirement Strategizer rebuilt around saved conversion strategies

At the user's request the strategizer is no longer one fixed dashboard that recommends a plan. It
is a tabbed page (`/finance/retirement/{tab?}`; "Roth conversions" plus a placeholder "More to
come" tab, `RetirementMore`) and the conversions tab compares strategies the user builds.

- **`fin_conversion_strategies`** (`ConversionStrategy`): a kind — `none`, `lump` (the whole
  balance in one year), `even` (equal parts across a window, 65–72 by default), `fill_bracket`
  (to the top of a named bracket each year, or of whichever bracket the income is in) — plus the
  projection it builds on (`scenario_id`, null = flows as entered), an inflation rate, a growth
  rate, and the heir (a charity, or a person with an income). Nearly everything is nullable and
  null means a fallback: inflation → the projection's bracket rate → the profile's; growth → the
  fleet's weighted rate; ages → the kind's usual window, never before today.
- **`ConversionBoard` is the new engine.** Per year: the projection's income and expenses
  (`ScenarioBoard::yearly()`, newly public), plus RMD, conversion and any traditional withdrawal
  as ordinary income, taxed whole by `TaxCalculator::flowTaxFor()` — so payroll tax, the LTCG
  brackets and state tax are all in, which the old planner did not have. Cash-flow rules were
  carried over from the old planner: while working only the conversion's extra tax (and IRMAA)
  must be found; once retired, expenses + tax + IRMAA are met from income and RMD, then taxable,
  traditional, Roth. Spending now comes from the projection's expenses, not a separate input.
- **IRMAA is modelled**: from 65, on the income of two years earlier, per person (two on a joint
  return — the spouse is assumed the same age), tiers rising with inflation.
- **Heirs**: an inherited traditional balance drawn in ten equal parts on top of the heir's own
  income, single filer, federal only, built-in deduction. A charity pays nothing. This replaces
  the old flat "heirs' tax rate".
- **Everything on the page is in today's dollars**, deflated by the strategy's own inflation
  rate. That is what makes a tax bracket or an IRMAA tier one flat line: `ThresholdChart` draws a
  year's income against those lines, with the conversion's share filled in gold.
- **Dropped from the old tool**: the goal picker, the "recommended" verdict and the per-year
  optimiser. The user asked to compare strategies they build, not to be handed one.
- **`RothConversionPlanner` and `tests/Feature/Finance/RothConversionPlannerTest.php` are still in
  the repo but nothing routes to the planner any more.** They were left because tests are not to
  be deleted without approval; two other test files (`StateTaxTest`, `RetirementAccountTest`) also
  call the planner directly. Removing all of it is a follow-up for the user to approve.
- JS trap hit while building: a `const window = …` in a `<script setup>` shadows the global, and
  `window.confirm` in the same file then throws. Named `convertsWhen` / `ages` instead.

## 2026-10-02 — Finance: a fifth conversion strategy that respects IRMAA

`fill_bracket_irmaa` ("Fill the tax or IRMAA bracket"): each year convert up to the top of the tax
bracket — the one the income is in, or a named one — or the top of the IRMAA tier the income is
already in, whichever comes first. Added to config, so the form and "one of each kind" pick it up.

- The IRMAA ceiling only applies from 63: a premium is set by the income of two years before, so
  earlier income cannot reach one, and capping it would only convert less for nothing.
- "The tier already in" is read from the year's income *before* the conversion, RMD included. So
  it never lets a conversion cross a line, but it cannot stop an RMD that is itself over one —
  such a year still pays IRMAA, and the strategy then fills to the top of that higher tier.
- It guards the conversion only. A traditional withdrawal later the same year, to cover
  spending, can still push income over a line.
- Kinds that fill a bracket are now marked `fills` in config; `ConversionBoard::window()` and the
  form read that instead of naming `fill_bracket`.

## 2026-10-02 — Finance: a strategy says where its conversion tax is paid from

Two columns on `fin_conversion_strategies`: `tax_payment` (`outside`, the default and the old
behaviour; `conversion`; `percent`; `flat` — config `finance.conversion_tax_payments`) and
`tax_outside_amount`, which the two split modes read as how much comes from **outside** the
conversion — a percentage of the conversion's tax, or dollars a year in today's money — with the
remainder withheld from the converted money.

- "The conversion's tax" is the tax the year owes with the conversion less the tax it would owe
  without it, worked out before any spending withdrawal. Each row now carries `conversion_tax` and
  `conversion_tax_withheld`; the year-by-year Tax column prints the first in parentheses.
- Withholding changes only where the tax is found and how much reaches the Roth: the whole
  conversion still leaves traditional and is still income. The 10% penalty on money withheld
  before 59½ is **not** modelled.
- The split is expressed from the outside side on purpose ("I can spare $10,000 a year from
  savings") — the user asked for a percentage or a flat amount without saying which side.

## 2026-10-02 — Finance: the old RothConversionPlanner removed

At the user's request, `app/Services/Finance/RothConversionPlanner.php` and
`tests/Feature/Finance/RothConversionPlannerTest.php` are deleted; `ConversionBoard` is the only
conversion engine. Coverage that was about something other than the old planner was kept:

- `StateTaxTest` — "counts state tax in the retirement strategizer" now runs a `fill_bracket`
  strategy through `ConversionBoard` (same figures: $16,100 converted, $805 of state tax).
- `RetirementAccountTest` — the compound-account balances check reads `ConversionBoard`'s
  `balances`.
- The "standard deduction is the top of a 0% bracket" check, which only exercised
  `TaxCalculator`, moved to `TaxCalculatorTest`.

## 2026-10-02 — Finance: Monte Carlo for the conversion strategies

The user's choices: a simple average-plus-volatility return model, random inflation as well, one
page-wide setting applied to every strategy, and a run count they set, with a background job when
it is too many for a request.

- **Engine.** `ConversionBoard::simulate()` is public and takes an optional `$path`: a return and
  an inflation rate for each year. The price index is now compounded year by year instead of
  `inflation ** years`, which is the same thing for a steady rate. `context()` is split out of
  `for()` so a run can simulate many times over one context.
- **`ConversionMonteCarlo`** draws standard normal scores once per run (Box–Muller on a seeded
  `Random\Randomizer(new Mt19937($seed))`) and every strategy scales the same scores by its own
  growth and inflation rates and the two volatility settings — common random numbers, so
  differences between strategies are not luck. Returns floor at −95%, inflation at −5%. Reported:
  how often the money lasts, how often each beats the first no-conversion strategy on what is left
  after heirs' tax, 10th/50th/90th percentiles of that, of tax with heirs and of IRMAA, and a
  yearly percentile band of the total balance (new row field `total_balance`).
- **Measured cost:** ~0.8 ms per simulation of a 41-year plan, so `finance.monte_carlo.page_limit`
  is 1,500 simulations (runs × strategies), about 1.2 s. The user guessed 50–100 runs; with four
  strategies a page load can actually do ~375.
- **Two paths.** Within the limit, the results are worked out on every page load as a deferred
  Inertia prop and never stored — so a GET writes nothing. Over it, `RunConversionMonteCarlo`
  (new `app/Jobs/Finance/`, `ShouldBeUnique` per user) stores results in `fin_monte_carlo_runs`
  with a sha256 of everything the run read; the page shows them dimmed with a "run again" prompt
  once that hash stops matching, and polls (`usePoll`, `only: ['monte_carlo']`) while a run is
  queued or running. Saving settings over the limit queues a run; "New markets" bumps the seed.
- **Needs a queue worker** for the background path. `composer run dev` already runs
  `queue:listen` (`QUEUE_CONNECTION=database`); production will need one too.
- Normal draws understate crashes and ignore bad years clustering; the page says so.

## 2026-10-03 — Finance: bracket-filling leaves room for the withdrawal that pays for it

Reported by the user on their own data: "Fill a tax bracket each year" pushed income into 37%
when it should have stopped at the top of 35%, and the first year's income on the chart looked
inflated against the table.

- **The bug.** The conversion was sized against the year's income *before* any withdrawal from
  traditional, and the withdrawal was worked out afterwards. With the conversion's tax paid from
  outside and not enough taxable savings to cover it, the engine drew the tax from traditional —
  more ordinary income, on top of a conversion that had already filled the bracket. On the user's
  data: $360,872 converted to the top of 35% ($656,700), then $268,967 withdrawn, landing at
  $925,667.
- **The fix.** `ConversionBoard::simulate()` now settles the conversion, its tax, and the
  withdrawal together (`$evaluate`/`$settle`): the conversion is worked out after the withdrawal
  it causes, so bracket-filling and IRMAA-tier-filling stop the two *together* at the line. On the
  same data: $209,847 converted plus $151,025 withdrawn = exactly $656,700. Lump and even
  conversions also now leave the withdrawal's share of the balance where it is.
- **The chart's dashed line** (`bracket_income_before`, `magi_before`) is now the year as it would
  have been without converting, worked out by settling the year again with no conversion — not
  "the same year minus the conversion", which still carried the withdrawal the conversion caused.
- **`marginal_rate` on a row is the bracket the year's last dollar is in**, not the rate on the
  next dollar: a year filled exactly to the top of 35% read as 37% before.
- The year-by-year table gained **Withdrawn** and **Taxable income** columns. Its **Income** is
  the projection's cash income; the chart plots taxable income, which is why they differed.

## 2026-10-03 — Finance: a conversion paid "from outside" is capped by the year's spare income

At the user's request. `tax_payment = 'outside'` (now labelled "From the year's spare income
(caps the conversion)") no longer means "from savings": the conversion's tax is paid from the
year's surplus — cash income + RMD − projected expenses − the year's other tax − IRMAA — and the
conversion is cut to the amount whose tax that surplus covers. Savings and traditional are never
drawn down to pay for a conversion, and a year with no surplus converts nothing.

- The amount is found by `ConversionBoard::affordable()`: regula falsi with the Illinois
  adjustment over the year's tax function, which is piecewise linear, so it lands within a dollar
  in a few steps. Simulation cost was unchanged (~0.8 ms) on the user's data.
- In a working year the surplus is not otherwise in the model (wages are assumed to cover their
  own year), so the conversion tax it pays is taken off the shortfall explicitly; in a retired
  year the surplus is already what the shortfall measures.
- The cap applies to every kind of strategy, so "One large conversion" with this setting converts
  only what one year's surplus can pay for (on the user's data, $227,563 of ~$1.9M), and "Even
  conversions" may not empty the balance by 72. The three withholding options are uncapped.
- Tests: the conversion-mechanics tests' retiree now has $1M a year of untaxed spare income, so
  those tests keep testing the mechanics rather than the cap; the cap has its own tests.

## 2026-10-03 — Finance: an audit of the Retirement Strategizer's arithmetic

At the user's request ("approach it like a senior CPA"). What was wrong, and what changed:

- **IRMAA tiers were read two years stale.** A premium for year Y is set by Y−2 income against
  *year Y's* tiers, which are indexed to inflation. The engine deflated the Y−2 income by the Y−2
  price index, i.e. compared it with tiers two years of inflation too low, overstating IRMAA at the
  margins. MAGI is now kept nominal and deflated by the premium year's index. The IRMAA chart and
  `magi_tier` show income in premium-year prices, and `fill_bracket_irmaa` stops at the premium
  year's tier (the strategy's inflation rate projecting the index two years on).
- **Medicare's first year was charged for twelve months.** It now starts in the birthday month.
- **No additional standard deduction at 65.** Added (`finance.tax.additional_deduction`, 2026:
  $2,050 unmarried, $1,650 a spouse, both spouses counted on a joint return) on top of whichever
  deduction is in force. `TaxCalculator`'s `deduction()`, `tax()`, `marginalRate()`,
  `grossCeiling()` and the `flowTaxFor()` closure take an optional `$age`. ScenarioBoard and the
  cashflow run rate pass it too, so projections agree with the strategizer. The temporary $6,000
  senior deduction (2025–2028, phased out above $75k/$150k) is deliberately not modelled.
- **One blended growth rate for all three buckets.** Each bucket now grows at its own holdings'
  weighted rate (an empty bucket takes the fleet blend); a strategy's own rate still overrides all
  three. Monte Carlo paths now carry `shocks` (points either side of each bucket's average) instead
  of absolute `returns`. The −95% floor moved into ConversionBoard.
- **"Tax, yours and theirs" left out IRMAA.** The comparison's headline cost now includes it
  (relabelled "Tax and IRMAA, yours and theirs", here and in the Monte Carlo card).
- **The "Taxable income" column was gross income.** It showed ordinary income *before* the
  deduction. Rows now carry `taxable_income` (after the age-aware deduction) and the bracket chart
  plots it against the bracket tops as taxable income, so the lines don't move at 65.
- **A working year's spare income ignored contributions.** Paying a conversion's tax "from spare
  income" now first sets aside the year's contributions to the accounts.
- **No early-withdrawal penalty.** Money taken from traditional before 59½ (a withdrawal, or
  conversion tax withheld from the converted money) now owes 10%, counted until the year of turning
  60; it is in the year's tax and in a `penalty` row field / `penalties` summary.
- **Monte Carlo inflation didn't reach expenses.** Projected expenses rise at the average
  inflation; in a random market they are now scaled by realized ÷ average price level, so a run of
  high inflation costs something. Income is left as projected.

Reviewed and found correct: the 2026 federal brackets, standard deductions and LTCG thresholds,
the Uniform Lifetime Table and the SECURE 2.0 RMD start ages, RMDs worked on the opening balance
and taken before converting, the 2026 IRMAA amounts, the heir's ten-year draw, withholding modes,
the fixed-point withdrawal settlement and the Monte Carlo percentiles and Box–Muller draws.

Still deliberately left out (the page footnote lists them): tax on growth and sales in the taxable
bucket, NIIT, the Social Security provisional-income formula (the flow's own taxed portion is used),
a survivor moving to single brackets, the SS wage base, and the Roth five-year rules.

## 2026-10-03 — Finance: a "Fixed amount each year" conversion strategy

At the user's request. New kind `fixed` (config `finance.conversion_strategies`, flagged
`amount`) with a new nullable column `fin_conversion_strategies.conversion_amount`, required by
validation for that kind only. Each year of its window (65–72 by default, like `even`) it converts
the amount — in today's dollars, raised by the strategy's inflation, so the page's rows show the
same figure each year — or the whole remaining traditional balance once that is less. It still
stops at the window's end, and the "from spare income" cap still applies to it like every kind.
"Start with one of each kind" creates one at `finance.defaults.conversion_amount` ($100,000).

## 2026-10-03 — Finance: a working year's surplus is saved, and a shortfall drawn

At the user's request, reversing the original "while working, income is assumed to cover the
year" simplification in ConversionBoard. Working and retired years now settle the same way:
`shortfall = expenses + tax + IRMAA + contributions − income − RMD − withheld`, met from taxable,
then traditional, then Roth, with a surplus saved to taxable. The only difference left is that a
working year's contributions are paid into their accounts. Previously a working year's surplus
beyond contributions vanished from the model, and a working year that overspent was never drawn
on. Consequence: the projection must list every expense, since anything it leaves out is saved.

## 2026-10-03 — Finance: armadas, household expenses, money routing, and two more retirement tools

Built in one unattended session at the user's request ("build to your heart's content"). Appended
to as each part landed. Nothing is committed; the working tree already held the uncommitted
fixed-amount conversion work, and this sits on top of it.

**Schema** — one migration, `2026_10_03_103743_create_fin_armadas_routing_and_retirement_tools`,
purely additive (new tables and nullable columns, no row rewritten): `fin_armadas`;
`armada_id` on holdings and flows; `parent_id` and `account_id` on flows; `fin_transfers`;
`fin_scenario_holdings`; four Social Security columns on `fin_profiles`;
`fin_social_security_strategies`; `fin_withdrawal_strategies`.

**Decisions, backend** (all in `app/Services/Finance` unless said):

- **Armadas are a pointer, not a container.** `fin_armadas` holds a name; a holding or flow points
  at one. Only top-level rows carry the pointer — an account inside another, an item inside a
  household expense, and a flow hung off a holding all follow their owner (`armada_key` on both
  models). Disbanding an armada nulls the pointers; nothing is deleted. Armada income and
  expenses are shown before tax, because tax is worked out on the household's incomes together.
- **Household expenses are a compound flow**, mirroring compound holdings: a flow in an
  `itemized` category (config; only `household` today) may hold items via `fin_flows.parent_id`.
  With items it comes to their sum everywhere (`Flow::amountInYear/plannedFor/annual_amount`);
  with none its own amount is the estimate. So the user can keep one total or itemise, as asked.
  Items are ordinary flows, so budget actuals and scenario settings work on them unchanged.
  `Fleet::flows()` now returns the top-level listing (as `holdings()` does) and
  `Fleet::flowLeaves()` the rows that carry an amount; `ScenarioBoard::rows()` flattens to leaves
  itself, so ConversionBoard and friends needed no change.
- **`account_id` vs `holding_id` on a flow.** `holding_id` is what a flow belongs to; the new
  `account_id` is the asset it is paid into or out of. Must be a leaf asset.
- **`FleetLedger`** is the new month-by-month walk that keeps what a holding *earned* apart from
  what was *moved*: growth, then contributions/payments, then routed flows, then transfers in
  `sort_order`. `FleetProjector` is untouched and still drives the overview, the projector and
  the snapshots. Simplifications are listed in the class comment; the two worth knowing are that
  a routed income is netted by the year's *average* tax rate, and that holdings' own monthly
  contributions still arrive from outside the model (zero one and add a fixed transfer to have
  it come out of an account). Contributions to assets stop at the retirement year.
- **A scenario can now adjust holdings** (`fin_scenario_holdings`): rate, monthly contribution,
  and pinned year-end values. Unlike a flow's pin, a holding's pin re-bases the years after it.
- **Transfers** come in three kinds (config `finance.transfer_kinds`): `sweep`, `fixed`,
  `top_up`. Money only leaves an asset; it may arrive at a debt, which it pays down.
- **Social Security** (`SocialSecurityBoard`): claiming rules in config
  `finance.social_security`. The benefit at full retirement age lives on the profile
  (`ss_monthly_benefit`, plus three spouse columns) and is edited on the tab itself rather than
  in Settings. Implements early reduction, delayed credits, the spousal top-up, the survivor
  keeping the larger benefit, and the provisional-income taxation test (which the conversion
  tab still deliberately leaves out). Someone already past a strategy's age claims *now*.
  "Use this" (`SocialSecurityFlows`) writes the strategy into Income & expenses as flows named
  "Social Security" / "Social Security (spouse)", replacing its own earlier ones by name.
- **Withdrawals** (`WithdrawalBoard`): the conversion model minus conversions, reading the same
  household through the newly extracted `ConversionBoard::world()`. Five orders and three
  spending rules (config `finance.withdrawal_strategies`, `finance.spending_rules`).
- **The "More to come" retirement tab is gone**, replaced by the two real ones; its test was
  rewritten to assert the new tabs and `RetirementMore.vue` deleted.
- **Sample fleet** now has four armadas, an itemised household expense (the nine day-to-day
  lines, same amounts as before), salary/rent/bills routed through checking, two transfers, and
  Social Security benefits for a couple.

**Frontend** (`resources/js/finance`): new pages `Armadas`, `Armada`, `SocialSecurity`,
`Withdrawals`; new components `ArmadaForm`, `TransferForm`, `ScenarioFlowGroup`,
`ScenarioHoldingRow`, `SocialSecurityStrategyForm`, `WithdrawalStrategyForm`. Armadas has its own
rail link. `HoldingForm` and `FlowForm` gained an armada select; `FlowForm` also "Paid into / Paid
from" and an item mode (`parent` prop) used by the budget. The budget page is where household
expenses are set up; income & expenses lists transfers; a scenario page now has a net-worth
chart, a by-armada table that also filters the lists, grouped household rows, and an Assets and a
Liabilities card. The user's armadas and accounts are shared with every page as lazy props
(`armadas`, `accounts` in `HandleFinanceInertiaRequests`) so the forms work wherever they open.
A new expense still defaults to the first *ordinary* category — "Household expenses" is listed
first but is picked on purpose.

**Verified.** All 852 tests in the suite pass; the new ones are in
`ArmadaTest`, `HouseholdExpenseTest`, `FleetLedgerTest`, `TransferTest`, `SocialSecurityTest`,
`WithdrawalStrategyTest`. `vite build` is clean. Every new and changed page was loaded in
headless Chromium against a throwaway SQLite database with the sample fleet — rows expanded and
each new dialog opened — with no console errors or failed requests. The headless shell needed
`libnspr4`, `libnss3` and `libasound2`, which are not installed on this machine; they were
unpacked from `.deb`s into the session scratchpad and put on `LD_LIBRARY_PATH`, nothing was
installed system-wide. The migration was then run on the dev Postgres database (additive; row
counts unchanged), because the dev server was already serving this working tree and would have
errored on the missing columns.

**Known gaps, for whoever picks this up:**

- The ledger does not draw retirement accounts into checking. In the sample fleet checking runs
  dry once the salary stops and the scenario page reports the bills it "could not pay" — true to
  the model, but the answer today is a transfer or a re-routed income, not anything automatic.
  Tying the withdrawal strategies into the ledger is the natural next step.
- Holdings' own monthly contributions still appear from outside the model (see FleetLedger).
- Social Security: someone *already* claiming should enter what they actually receive as an
  ordinary income; the tool assumes nobody has claimed yet. No earnings test.
- Applying a Social Security strategy does not write the survivor's step-up into the flows.
- The withdrawal tab has no Monte Carlo and no heir tax; the conversion tab has both.
- None of the new pages has been laid out for a phone, like the rest of the sub-project.
- Two rules were recorded in `.ai/rules` (`finance.md`, `models-finance.md`).

## 2026-10-03 — Finance: "Est. Leftover Taxes" and "Est. Inheritable Amount" on the conversion comparison

At the user's request. The Side by side table on the Roth conversions tab drops "Tax left to
heirs" and "Tax and IRMAA, yours and theirs", and replaces "Left after heirs' tax" with three
rows: "All three together", "Est. Leftover Taxes" and "Est. Inheritable Amount".

- `leftover_tax` = the existing heir tax on the traditional balance **plus** a new `gains_tax`:
  long-term capital gains tax on the growth left in the taxable bucket. `inheritable` =
  ending balance − `leftover_tax`.
- The gain needs a basis, so `ConversionBoard::simulate()` now tracks one for the taxable bucket:
  the opening balance is all basis (the fleet records no cost basis), a saved surplus and
  contributions add to it, and a withdrawal takes its proportional share out.
- The gain is realised the way the traditional balance is drawn — ten equal parts, single filer,
  built-in LTCG brackets, stacked on the heir's income plus that year's tenth of traditional. A
  charity pays nothing. The stepped-up basis an heir would get is ignored **on purpose**: the
  user wants the figure to stand in for tax on dividends and sales the model never charges along
  the way, and for the worst case of having to draw on that money in retirement. The page
  footnote and the method's docblock both say so; don't "correct" it to zero.
- The old summary keys (`heir_tax`, `tax_with_heirs`, `ending_after_heir_tax`) are still
  returned: the Monte Carlo card and its stored runs read them, and were not asked to change.

**Later the same day: the gains part is the owner's tax, not an heir's.** At the user's request,
following from the rationale above. `gainsTax()` now uses the profile's own filing status,
deduction (with the age-65 addition) and LTCG brackets, and stacks the gain — still in ten equal
parts — on the owner's income in the plan's last year, conversions apart, plus any gains already
in that year. The heir's income and `heir_is_charity` no longer touch it. Federal only, like the
heir tax beside it; the profile's state brackets are not applied to it.

## 2026-10-03 — Finance: the IRMAA-aware strategy stops $100 short of the cliff

Reported by the user as a three-year IRMAA spike on "Fill the tax or IRMAA bracket". Cause: the
strategy filled to the dollar, so a filled year's income, deflated two years later, sat *exactly*
on the tier's ceiling ($500,000 on the user's data, ages 70–72), and floating-point rounding
tipped it a hair over — into the top tier for 2034–2036. `ConversionBoard::IRMAA_MARGIN` (100,
today's dollars) now keeps the conversion that far below the line, at the user's request; income
already inside the margin converts nothing. In a Monte Carlo market realised inflation differs
from the strategy's, so the margin narrows the risk there rather than removing it.

## 2026-10-03 — Finance: a holding area for conversion strategies

At the user's request, so many strategies can be built on different projections while only a few
are compared — and so the page only pays for the few. Everything up to this point was committed
to `main` first (bdf7433); this entry's work is uncommitted.

- New column `fin_conversion_strategies.is_compared` (migration
  `add_is_compared_to_fin_conversion_strategies`; existing rows past each user's sixth are moved
  to the holding area). `ConversionBoard::context()` loads only compared strategies, so neither
  the page nor the Monte Carlo runs simulate a held one; held strategies reach the page as
  settings alone (`held`).
- Config `finance.conversion_comparison`: `default` 6 is how many are compared before a newly
  made strategy (built, copied or a starter) goes to the holding area instead; `max` 12 is the
  most the comparison takes when strategies are brought in by hand. 12 is a guess at where the
  table and the six chart colours stop being readable — raise it in config if wanted.
- Routes: `PUT retirement/strategies/comparison` replaces the whole comparison;
  `POST|DELETE retirement/strategies/{strategy}/compare` moves one in or out.
- `StrategyHoldingArea.vue` sits above Side by side: cards with edit, copy, add to comparison
  and remove; filters by projection and by strategy type; "replace comparison" with everything
  filtered or with the picked cards. Picking works two ways, a checkbox and Ctrl/Cmd-click, both
  on purpose — the user means to keep one after trying them. Each compared strategy's column
  gained a "move to the holding area" button.

**One of each kind, per projection** (2026-10-04, uncommitted). `POST retirement/strategies/starters`
now takes `scenario_id` (one set on that projection; null or absent is "as entered") or
`every_projection` (a set for each saved projection, or one as-entered set when none is saved —
"as entered" is not itself counted as a projection once any are saved). Each is named
"{kind} · {projection}" and placed by the usual rule, so the first six are compared and the rest
held. The holding area has a "One of each type for [projection]" control; the empty state's
button became "Start with one of each kind for each projection".

**Follow-ups on the holding area** (2026-10-04). Strategies can no longer be deleted from Side
by side — only from a holding-area card — so a column's buttons are edit, copy and move to the
holding area. Side by side has a "Clear Comparison" button (`DELETE
retirement/strategies/comparison`), which holds every compared strategy and removes none. The
projection label on a holding-area card takes the colour that projection has on the Projections
& scenarios page (the chart colour at its position in the default order; "as entered" is grey).

**Roth report in each year's own dollars** (2026-10-06, uncommitted). Reverses the earlier
"everything in today's dollars" decision for the Roth conversions tab, at the user's request, to
try how it reads. `ConversionBoard::simulate()` no longer deflates its rows or summary; each row
carries `bracket_lines` and `irmaa_lines` (the thresholds of that year, the IRMAA ones two years
on), and `ThresholdChart` draws them as rising lines rather than flat ones.
- The Monte Carlo runs still use today's dollars (`simulate(..., inTodaysDollars: true)`): each
  market has its own inflation, so nominal figures cannot be ranked across them. The fan chart's
  steady line reads `total_balance_today` to match. The page says so in both places.
- Heir tax and the savings-gains tax are still worked out in today's dollars on today's tables,
  then raised to the price level the plan ends at.
- Inputs are unchanged: a fixed conversion amount and a flat tax amount are still entered in
  today's dollars.
- Known cost: Side by side now compares nominal totals, so two strategies with different
  inflation rates are no longer like for like. The Withdrawals tab was not touched.

**Roth report: a switch between the two dollars** (2026-10-06, uncommitted). The user liked parts
of each, so the page header now has "Each year's dollars / Today's dollars". `ConversionBoard::for()`
sends every compared strategy worked out both ways (`rows`/`summary`, and the same under `today`)
and `Retirement.vue` only picks between them, so switching is instant and needs no request. The
choice is kept in the browser's localStorage (`finance.roth.dollars`), not on the profile; it
opens in each year's dollars. Monte Carlo is in today's dollars under either setting.

**Roth report opens in today's dollars** (2026-10-06, uncommitted). Reverses the default in the
entry above at the user's request: the switch starts on "Today's dollars" unless the browser has
"each year's" saved.
