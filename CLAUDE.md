<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.3. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>

## Project conventions (not managed by Boost)

Everything in this section sits **outside** the `<laravel-boost-guidelines>` block above on
purpose. `php artisan boost:install` / `boost:update` regenerates that block's contents and
will silently discard anything written inside it.

### Setup log

Record significant scaffolding, configuration and tooling work in
[`docs/setup-log.md`](docs/setup-log.md) as you do it — installs, env changes, and any
decision whose rationale isn't obvious from the resulting code. **Append; don't rewrite
retroactively.** If a later change reverses an earlier decision, add a new dated entry saying
so rather than editing the old one.

The log lives in `docs/` rather than `.claude/` (where the sibling `eb-portfolio` project
keeps its own) specifically so it stays tracked in git — `/.claude/` is gitignored here.

### Stack constraints

Blade + jQuery, deliberately. **No Livewire, Inertia, Vue, React, Alpine, or Bootstrap** in
the base application. Those arrive only inside individual sub-projects, which is what this
app exists to hold. Alpine in particular was removed from Breeze's scaffolding on purpose —
don't reintroduce it to "fix" the dropdown or modal components, which are jQuery and native
`<dialog>` respectively.

**Tailwind is v4, CSS-first.** There is no `tailwind.config.js` and there should not be one;
design tokens go in the `@theme` block in `resources/css/app.css`. Don't add a `@config`
compatibility bridge.

Custom styles that a utility class can't express go in `resources/sass/_components.scss`.
Tokens split the same way: values a Blade template would want as a class belong in `@theme`,
values only SASS needs belong in `_variables.scss`.

### Code style

These mirror `~/projects/eb-portfolio`; that project's `CLAUDE.md` carries the full rationale
for each.

**Import ordering — `Illuminate\` first.** Within a file's `use` block, every `Illuminate\`
import comes first, then everything else, alphabetical within each group:

```php
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Http\Controllers\Controller;
use App\Models\Project;
```

`pint.json` sets `"ordered_imports": {"sort_algorithm": "none"}` because the `laravel`
preset's alphabetical sort would put `App\` above `Illuminate\` and undo this on every
`pint --dirty`. Consequences: **Pint will not fix a misordered `use` block and will not
complain about one** — get it right when writing the file. `no_unused_imports` still runs, so
dead imports are still removed. Don't "fix" `pint.json` by restoring the default sort.

**Model member ordering.** Traits and properties → accessors and helper methods → query
scopes → **relationships last**, with `// Scopes` and `// Relationships` header comments
marking the groups. Only add a header for a group that exists.

`pint.json` sets `class_attributes_separation` to `method: none`, so Pint strips blank lines
between adjacent methods and each group closes up. A `//` header comment keeps its
surrounding blank lines, which is what separates the groups; a docblock is the only way to
space two individual methods apart. When editing that rule, specify **all four** `elements`
keys — a partial map replaces the defaults rather than merging.

**Scope naming.** Filtering scopes read as explicit inclusion or exclusion:
`scopeOnlyPublished()` / `scopeNotComplete()`, so the call site is unambiguous about whether
it filters or merely sorts. The model's default ordering is `scopeInDefaultOrder()`, sitting
naturally beside Laravel's own `inRandomOrder()`. Other non-filtering scopes take a plain
descriptive name.

**Route model binding.** Don't define `getRouteKeyName()`. Declare the binding column in the
route instead — `Route::get('/projects/{project:slug}', ...)` — so the column is visible at
the URL that uses it. Watch the silent-fallback trap: removing `getRouteKeyName()` from a
model whose route has no explicit field doesn't error, it quietly starts binding on `id`.

**Derived state: accessors, not no-arg helpers.** A parameterless helper deriving state from
existing attributes is an accessor returning `Illuminate\Database\Eloquent\Casts\Attribute` —
`protected function isPublished(): Attribute`, read as `$project->is_published`. **Always add
a `@property-read` docblock on the class**: accessors are invisible to static analysis
without one, and a typo evaluates to `null` rather than erroring, so a conditional silently
takes the wrong branch. Helpers that take parameters stay methods.

**Conditionals: special case in the branch, plain value as the fall-through.** Test the
positive condition and let the untransformed value be the final return:

```php
if ($complete) {
    return $this->update(['completed_at' => $this->completed_at ?? now()]);
}

return $this->update(['completed_at' => null]);
```

This doesn't replace guard clauses — `abort_unless(...)` and friends still belong at the top
of the method. The distinction is that a guard rejects a case that shouldn't proceed, while
this is about two legitimate return values.

### Progressive enhancement

Every jQuery behaviour has a working no-JS path, and that's a requirement rather than a nice
to have. The milestone toggle is the reference example: real `<form>`s with submit buttons
that `app.js` removes before taking over the `change` event. When adding interactivity, make
the server-rendered version work first and layer jQuery on top.
