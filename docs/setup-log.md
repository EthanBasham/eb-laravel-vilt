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
