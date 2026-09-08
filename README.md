# laravel-vilt

A hub for a series of small sub-projects built to learn the **VILT** stack — Vue, Inertia,
Laravel, Tailwind — one piece at a time. Each sub-project lives as a `Project` record with
its own milestone checklist, so progress is visible rather than remembered.

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
git clone <this repo> laravel-vilt && cd laravel-vilt
composer install
cp .env.example .env
php artisan key:generate
```

**2. Create the database role and database.**

```bash
sudo -u postgres psql \
    -c "CREATE ROLE laravel_vilt WITH LOGIN PASSWORD 'choose-a-password';" \
    -c "CREATE DATABASE laravel_vilt OWNER laravel_vilt;"
```

Put that password in `DB_PASSWORD` in `.env`. On the machine this was scaffolded on, the
generated password is already stored at `~/secrets/laravel-vilt-local-db-password`.

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
dev@laravel-vilt.test  /  password
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
php artisan test --filter=Milestone # run one file's worth
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
auto-dismissing status messages, and the milestone toggle.

Everything is written as progressive enhancement. The mobile nav is CSS-collapsed below `md`
and always open above it, so with JS off it's permanently expanded rather than unreachable.
The milestone checkboxes sit inside real `<form>`s with submit buttons; `app.js` removes
those buttons and takes over the `change` event, so turning JS off costs a page reload and
nothing else.

The **AJAX example** is that milestone toggle: `PATCH` to
`projects.milestones.update`, validated by `UpdateMilestoneRequest`, answered as JSON with
the recomputed progress label (which the handler writes back into `#milestone-progress`).
The same controller action returns a redirect when the request isn't asking for JSON.

### Routes and models

```
GET    /                                              home            HomeController@index
GET    /projects                                      projects.index  ProjectController@index
GET    /projects/{project:slug}                       projects.show   ProjectController@show
PATCH  /projects/{project:slug}/milestones/{milestone} …update        MilestoneController@update   [auth]
GET    /dashboard                                     dashboard                                    [auth, verified]
GET    /profile                                       profile.edit    ProfileController@edit       [auth]
```

Plus Breeze's `routes/auth.php`.

`Project` **hasMany** `Milestone`; `Project` **belongsTo** `User`. Deleting a user nulls
`projects.user_id` (the log outlives the account); deleting a project cascades its
milestones (they mean nothing without it).

Projects bind on `slug`, declared explicitly in the route as `{project:slug}` rather than via
`getRouteKeyName()` — see the note in `docs/setup-log.md` on why the binding column belongs
at the URL that uses it.

### Where things live

```
app/Http/Controllers/    HomeController, ProjectController, MilestoneController
app/Http/Requests/       UpdateMilestoneRequest
app/Models/              Project, Milestone, User
database/migrations/     projects, milestones
database/seeders/        ProjectSeeder — the real starter set, not faker noise
resources/css/app.css    Tailwind entry + @theme tokens
resources/sass/          custom SASS layer
resources/js/app.js      jQuery entry
resources/views/
  layouts/               app (site shell), guest (auth screens)
  partials/              header, footer
  components/            Breeze's set, plus project-card; dropdown & modal are de-Alpined
  projects/              index, show
tests/Feature/           HomeTest, ProjectTest, MilestoneTest, plus Breeze's auth tests
docs/setup-log.md        why everything above is the way it is
```

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
