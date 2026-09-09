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

Re-pinning is idempotent. `syncWithoutDetaching` alone would leave an existing row's pivot
untouched, so the position would silently not refresh; an explicit `updateExistingPivot`
follows it.

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
