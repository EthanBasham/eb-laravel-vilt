<x-app-layout title="Home">
    <section class="bg-white">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
            <div class="max-w-3xl">
                <p class="text-sm font-semibold uppercase tracking-wide text-brand-600">
                    Vue · Inertia · Laravel · Tailwind
                </p>

                <h1 class="mt-3 text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl">
                    A workshop for the VILT stack.
                </h1>

                <p class="mt-6 text-lg leading-relaxed text-gray-600">
                    This is a hub for a series of small, self-contained sub-projects — each one built to
                    learn a specific piece of the stack properly rather than in the abstract. Every project
                    keeps its own set of milestones, so progress is visible instead of remembered.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <a
                        href="{{ route('projects.index') }}"
                        class="inline-flex items-center rounded-md bg-brand-600 px-5 py-3 text-sm font-medium text-white hover:bg-brand-700"
                    >
                        Browse the projects
                    </a>

                    @guest
                        <a
                            href="{{ route('register') }}"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Create an account
                        </a>
                    @endguest
                </div>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="featured-heading">
        <div class="flex items-baseline justify-between gap-4">
            <h2 id="featured-heading" class="text-2xl font-semibold tracking-tight text-gray-900">
                Featured projects
            </h2>

            <a href="{{ route('projects.index') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">
                View all &rarr;
            </a>
        </div>

        @if ($featured->isEmpty())
            <p class="mt-6 rounded-lg border border-dashed border-gray-300 p-8 text-center text-gray-500">
                No featured projects yet. Run <code class="font-mono text-sm">php artisan db:seed</code> to load the starter set.
            </p>
        @else
            <ul role="list" class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($featured as $project)
                    <li>
                        <x-project-card :project="$project" />
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-app-layout>
