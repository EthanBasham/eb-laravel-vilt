# eb-laravel-vilt

The base application for a series of small sub-projects built to learn the **VILT** stack —
Vue, Inertia, Laravel, Tailwind — one piece at a time. This repo is the foundation they mount
onto; it deliberately has no domain model of its own.

This initial scaffold is deliberately **Blade + jQuery**: it establishes the Laravel,
Tailwind, SASS and Vite half of the stack on solid ground first. Vue and Inertia arrive as
sub-projects on top of it, which is the point of the "Inertia Fundamentals" and "Vue 3
Composition API" entries in the seeded data.

---

## Stack

| Layer | Choice | Version |
|---|---|---|
| Framework | Laravel | 13.31.0 |
| Language | PHP | 8.3 |
| Database | PostgreSQL | 16 |
| Templating | Blade | — |
| Auth | Laravel Breeze (Blade stack) | 2.4.2 |
| Utility CSS | Tailwind CSS | 4.3.3 |
| Custom CSS | SASS (`.scss`) | 1.104.0 |
| Interactivity | jQuery | 4.0.0 |
| Bundler | Vite + `laravel-vite-plugin` | 8.2.2 / 3.2.0 |
| Tests | Pest | 4.7.8 |
| Formatting | Laravel Pint | 1.31.1 |

Versions are **pinned exactly** in `composer.json` and `package.json`. The one exception is
the `php` platform constraint, kept as `8.3.*` — an exact patch pin would break
`composer install` on any other 8.3.x.

No SPA framework, no Alpine, no Bootstrap, no CSS-in-JS. See
[`docs/setup-log.md`](docs/setup-log.md) for why each of those calls was made.

---

## Prerequisites

- PHP 8.3 with the `pdo_pgsql` and `sqlite3` extensions (the test suite runs on in-memory
  SQLite; the app itself runs on PostgreSQL)
- Composer 2
- Node 20+ and npm
- PostgreSQL 16 running locally

---

## Setup

**1. Install dependencies and create the env file.**

```bash
git clone <this repo> eb-laravel-vilt && cd eb-laravel-vilt
composer install
cp .env.example .env
php artisan key:generate
```

**2. Create the database role and database.**

```bash
sudo -u postgres psql \
    -c "CREATE ROLE eb_laravel_vilt WITH LOGIN PASSWORD 'choose-a-password';" \
    -c "CREATE DATABASE eb_laravel_vilt OWNER eb_laravel_vilt;"
```

Put that password in `DB_PASSWORD` in `.env`. On the machine this was scaffolded on, the
generated password is already stored at `~/secrets/eb-laravel-vilt-local-db-password`.

**3. Migrate, seed, and build assets.**

```bash
php artisan migrate --seed
npm install
npm run build
```

Steps 1, 3 and the Postgres health check are bundled into one command:

```bash
composer run setup
```

Seeding creates a sign-in you can use immediately:

```
dev@eb-laravel-vilt.test  /  password
```

---

## Running it

```bash
composer run dev
```

Starts PostgreSQL if it isn't up, then runs the PHP server, queue worker, log tailer and
Vite dev server together. The app is at <http://localhost:8000>.

To run the two halves separately:

```bash
php artisan serve      # http://localhost:8000
npm run dev            # Vite dev server + hot reload
```

> **Why `localhost` and not `127.0.0.1`.** `vite.config.js` pins the dev server to
> `localhost` so it matches `APP_URL`'s hostname. They resolve to the same address but are
> *different origins* to a browser, and module scripts are CORS-checked — mismatch them and
> the asset tags are silently blocked.

---

## Common commands

```bash
php artisan test --compact          # run the suite
php artisan test --filter=Pipeline  # run one file's worth
vendor/bin/pint --format agent      # format PHP (run after any PHP change)
vendor/bin/pint --test              # check formatting without writing
npm run build                       # production asset build
php artisan migrate:fresh --seed    # rebuild the database from scratch
php artisan route:list --except-vendor
```

---

## CI

`.github/workflows/ci.yml` runs on every push to `main`, every pull request, and on demand
via **Actions → CI → Run workflow**. Two jobs run in parallel:

| job | does |
|---|---|
| `php` | `composer validate`, `pint --test`, `php artisan test`, then migrate + seed against a real PostgreSQL 16 service, then a rollback/re-apply cycle |
| `assets` | `npm ci`, `npm run build`, then asserts all three entrypoints landed in the Vite manifest |

Two things it checks that the local test suite can't:

- **Migrations against real PostgreSQL.** The suite runs on in-memory SQLite, which silently
  tolerates index and foreign-key definitions Postgres rejects. The service container catches
  that.
- **`down()` actually works.** Nothing else ever calls the migrations' rollback path, so the
  workflow rolls back and re-applies to keep it honest.

Note the suite calls `withoutVite()` (in `tests/Pest.php`), so tests don't depend on
`public/build` existing — that directory is gitignored, and without the stub every
view-rendering test fails on a fresh clone. Verifying the assets genuinely compile is the
`assets` job's responsibility instead.

---

## How the pieces fit together

### Assets

Three Vite entrypoints, all loaded by `@vite([...])` in `resources/views/layouts/app.blade.php`:

| Entry | Holds |
|---|---|
| `resources/css/app.css` | Tailwind import, `@plugin` forms, and the `@theme` design tokens |
| `resources/sass/app.scss` | the custom SASS layer (`_variables`, `_mixins`, `_base`, `_components`) |
| `resources/js/app.js` | jQuery, exposed globally, plus every behaviour on the site |

**There is no `tailwind.config.js`.** Tailwind v4 is configured from CSS: colours and fonts
are declared in the `@theme` block of `app.css` and become utilities automatically
(`bg-brand-600`, `font-sans`). Content scanning is automatic too — v4 walks the project
looking for template files, so Blade and JS paths need no configuration. It does respect
`.gitignore`, which is why `app.css` carries one explicit `@source` line for Laravel's
pagination views inside `vendor/`.

**Tailwind vs. SASS — which goes where.** Reach for a Tailwind utility in the Blade template
first. Drop into `resources/sass/_components.scss` when a utility class can't express what's
needed: pseudo-elements (`::backdrop`, `::selection`), keyframes, or state that depends on an
ancestor class. Design tokens follow the same split, documented in `_variables.scss`: if a
template would ever want the value as a class it belongs in `@theme`; if only a `.scss` rule
needs it, it belongs in `_variables.scss`.

### jQuery

`resources/js/app.js` exposes `$` globally, registers the CSRF token on every AJAX request,
and holds five behaviours: the mobile nav toggle, dropdown menus, `<dialog>`-based modals,
auto-dismissing status messages, and the pipeline check.

Everything is written as progressive enhancement. The mobile nav is CSS-collapsed below `md`
and always open above it, so with JS off it's permanently expanded rather than unreachable.

The **AJAX example** is the *pipeline check* on the home page — a deliberately trivial
endpoint whose only job is to prove the front-end stack is connected end to end: Blade markup,
the jQuery bundle, the CSRF header registered by `app.js`, `PipelineCheckRequest` validation,
and a JSON reply rendered without a reload. Its `422` branch surfaces Laravel's validation
errors in place.

It is also a working plain form. With JS off it posts normally and
`PipelineCheckController` redirects back with the result in the session, which the same markup
renders. Delete the whole thing once a real sub-project gives the scaffold something better to
exercise.

### Routes and models

```
GET    /                  home                 HomeController@index
POST   /pipeline-check    pipeline-check.store PipelineCheckController@store  [throttle:20,1]
GET    /dashboard         dashboard                                           [auth, verified]
GET    /profile           profile.edit         ProfileController@edit         [auth]
```

Plus Breeze's `routes/auth.php`.

**There are no domain models.** The only table beyond Laravel's and Breeze's own is `users`.
Sub-projects bring their own migrations, models and routes; the scaffold deliberately doesn't
presuppose what they'll be.

When you do add one, two conventions from `CLAUDE.md` apply immediately: declare the binding
column in the route (`{thing:slug}`) rather than via `getRouteKeyName()`, and index foreign
key columns explicitly — Postgres, unlike InnoDB, won't do it for you.

### Where things live

```
app/Http/Controllers/    HomeController, PipelineCheckController, ProfileController
app/Http/Requests/       PipelineCheckRequest, ProfileUpdateRequest
app/Models/              User
database/migrations/     users, cache, jobs — Laravel's own, nothing else yet
database/seeders/        DatabaseSeeder — a local dev sign-in, nothing more
resources/css/app.css    Tailwind entry + @theme tokens
resources/sass/          custom SASS layer
resources/js/app.js      jQuery entry
resources/views/
  layouts/               app (site shell), guest (auth screens)
  partials/              header, footer
  components/            Breeze's set; dropdown & modal are de-Alpined
tests/Feature/           HomeTest, PipelineCheckTest, plus Breeze's auth tests
docs/setup-log.md        why everything above is the way it is
```

---

## Sub-projects

### World of Tanks dashboard — `/wot`

The first sub-project, and the first SPA. **Inertia + Vue, mounted as an island**: it has its
own Vite entrypoint (`resources/js/wot/app.js`), its own root view
(`resources/views/wot.blade.php`), and `HandleInertiaRequests` applied to that route group
only. The Blade half of the site ships no Vue; the dashboard ships no jQuery.

```
GET    /wot                    wot.dashboard      account summary + garage table   [auth]
GET    /wot/connect            wot.link.create    start the Wargaming OpenID flow  [auth]
GET    /wot/connect/callback   wot.link.callback  receive the token                [auth]
DELETE /wot/connect            wot.link.destroy   unlink and revoke                [auth]
```

**Setup.** Get an application ID from <https://developers.wargaming.net> and put it in
`.env`:

```
WARGAMING_REALM=na
WARGAMING_APPLICATION_ID=your-id-here
```

Then check it works and load the vehicle encyclopedia:

```bash
php artisan wot:ping            # confirms the credentials reach the API
php artisan wot:sync-vehicles   # ~1,000 vehicles; scheduled weekly thereafter
```

**Mind the application type.** A *Server* application validates the calling IP against up to
5 registered addresses (20 req/s) and returns `407 INVALID_IP_ADDRESS` from anywhere else — so
a changing home IP will break it. A *Standalone* application skips the IP check (10 req/s per
IP). `wot:ping` tells you which situation you're in, and the error message names the fix.

**Realms are not interchangeable.** Accounts, account IDs and application IDs each belong to
exactly one region. An NA key will not work against `api.worldoftanks.eu`.

**How it fits together.**

- `WargamingClient` wraps the API. It exists mainly because the API answers **HTTP 200 for
  application-level errors** and puts the real outcome in a `status` field — so every call is
  funnelled through one place that unwraps the envelope and throws.
- `AccountDashboard` assembles the page: summary stats, plus per-vehicle rows joined against
  the local encyclopedia copy. Caching lives here rather than in the client, because this is
  the layer that knows how stale the data may be.
- `wot_accounts` holds the OpenID link. The access token is stored with the `encrypted` cast
  and revoked at Wargaming's end on disconnect.
- `wot_vehicles` is a local copy of the encyclopedia, keyed by Wargaming's `tank_id`.

Tests run entirely on `Http::fake()`, so CI needs no API credentials.

---

## Conventions

Mirrors `~/projects/eb-portfolio`, whose `CLAUDE.md` is the fuller reference. The short
version:

- **Imports:** every `Illuminate\` import first, then everything else, alphabetical within
  each group. Pint's import sorting is switched off in `pint.json` to allow this, so it is
  **not** auto-fixed — order them correctly when writing the file.
- **Model member order:** properties → accessors/helpers → scopes → relationships last, with
  `// Scopes` and `// Relationships` header comments.
- **Scope naming:** filtering scopes are `scopeOnly<Filter>` / `scopeNot<Filter>`
  (`onlyPublished()`, `notComplete()`); the default sort is `scopeInDefaultOrder()`.
- **Derived state:** parameterless helpers that derive from attributes are accessors
  returning `Attribute`, with a `@property-read` docblock on the class.
- **Conditionals:** put the transforming case inside the `if` and let the plain value be the
  final fall-through return. Guard clauses are exempt.
- PSR-12 via Pint; run `vendor/bin/pint --format agent` after touching PHP.

---

## Setup log

[`docs/setup-log.md`](docs/setup-log.md) records the whole scaffolding process in order —
every install, every decision, and the reasoning behind the non-obvious ones. Append to it
when you do configuration or tooling work; don't rewrite it retroactively.
