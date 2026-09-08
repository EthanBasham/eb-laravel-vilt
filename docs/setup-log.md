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
