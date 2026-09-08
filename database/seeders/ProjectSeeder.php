<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;

class ProjectSeeder extends Seeder
{
    /**
     * Seed a starting set of VILT sub-projects.
     *
     * These are deliberately real rather than faker noise: they're the actual
     * learning track this app exists to hold, so a fresh `migrate --seed` gives
     * something worth looking at instead of lorem ipsum.
     *
     * @var list<array{title: string, summary: string, stack: string, featured: bool, milestones: list<array{0: string, 1: bool}>}>
     */
    private const PROJECTS = [
        [
            'title' => 'VILT Foundations',
            'summary' => 'The baseline: Laravel 13 on PostgreSQL, Blade layouts, Vite bundling Tailwind, SASS and jQuery, with Breeze handling auth.',
            'stack' => 'Laravel, Blade, Tailwind, SASS, jQuery, Vite, PostgreSQL',
            'featured' => true,
            'milestones' => [
                ['Scaffold the app and point it at PostgreSQL', true],
                ['Wire Vite to compile Tailwind, SASS and jQuery together', true],
                ['Install Breeze and protect a route', true],
                ['Prove the jQuery pipeline with a real AJAX interaction', true],
                ['Add feature tests covering a route and a model', true],
            ],
        ],
        [
            'title' => 'Inertia Fundamentals',
            'summary' => 'Swap Blade responses for Inertia page components and learn where the server/client boundary actually falls in a monolith-first SPA.',
            'stack' => 'Laravel, Inertia, Vue, Vite',
            'featured' => true,
            'milestones' => [
                ['Install Inertia and render a first page component', false],
                ['Move a Blade page to Inertia without changing its URL', false],
                ['Share auth state through Inertia middleware', false],
                ['Handle validation errors the Inertia way', false],
            ],
        ],
        [
            'title' => 'Vue 3 Composition API',
            'summary' => 'Build the component vocabulary — reactivity, composables, props and events — independently of the Laravel side.',
            'stack' => 'Vue, Vite',
            'featured' => true,
            'milestones' => [
                ['Reactive state with ref and reactive', false],
                ['Extract a reusable composable', false],
                ['Component communication: props down, events up', false],
                ['Slots and scoped slots', false],
            ],
        ],
        [
            'title' => 'Tailwind Design System',
            'summary' => 'Turn the placeholder token set into a real design system: a considered colour ramp, type scale, and a small library of components.',
            'stack' => 'Tailwind, SASS, Blade',
            'featured' => false,
            'milestones' => [
                ['Replace the placeholder brand ramp with real values', false],
                ['Define a type scale and spacing rhythm', false],
                ['Build the core component set: buttons, forms, cards', false],
                ['Audit the whole thing for colour contrast', false],
            ],
        ],
        [
            'title' => 'Testing with Pest',
            'summary' => 'Go past the two example tests: datasets, architecture tests, browser tests, and a coverage threshold that actually holds.',
            'stack' => 'Laravel, Pest',
            'featured' => false,
            'milestones' => [
                ['Feature-test every public route', false],
                ['Cover the model scopes with datasets', false],
                ['Add architecture tests', false],
                ['Set and enforce a coverage floor in CI', false],
            ],
        ],
        [
            'title' => 'Deployment Pipeline',
            'summary' => 'Get this onto real infrastructure with a GitHub Actions pipeline, mirroring the setup already running for eb-portfolio.',
            'stack' => 'GitHub Actions, AWS, PostgreSQL',
            'featured' => false,
            // Nothing started yet — this one exercises the "No milestones yet"
            // empty state on the project page.
            'milestones' => [],
        ],
    ];

    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'dev@laravel-vilt.test'],
            ['name' => 'Ethan Basham', 'password' => 'password', 'email_verified_at' => now()],
        );

        foreach (self::PROJECTS as $index => $attributes) {
            $project = Project::updateOrCreate(
                ['slug' => Str::slug($attributes['title'])],
                [
                    'user_id' => $owner->id,
                    'title' => $attributes['title'],
                    'summary' => $attributes['summary'],
                    'description' => $attributes['summary'],
                    'stack' => $attributes['stack'],
                    'repo_url' => null,
                    'demo_url' => null,
                    'is_featured' => $attributes['featured'],
                    'sort_order' => $index,
                    'published_at' => now()->subDays(count(self::PROJECTS) - $index),
                ],
            );

            // Rebuilt from scratch each run so re-seeding can't leave orphaned
            // milestones behind from a previous version of this list.
            $project->milestones()->delete();

            foreach ($attributes['milestones'] as $order => [$title, $isComplete]) {
                Milestone::create([
                    'project_id' => $project->id,
                    'title' => $title,
                    'notes' => null,
                    'sort_order' => $order,
                    'completed_at' => $isComplete ? now()->subDays(20 - $order) : null,
                ]);
            }
        }
    }
}
