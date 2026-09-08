<x-app-layout :title="$project->title" :description="$project->summary">
    <x-slot name="header">
        <nav aria-label="Breadcrumb" class="mb-2 text-sm text-gray-500">
            <a href="{{ route('projects.index') }}" class="hover:text-gray-700">Projects</a>
            <span aria-hidden="true" class="mx-2">/</span>
            <span class="text-gray-700">{{ $project->title }}</span>
        </nav>

        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $project->title }}</h1>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <p class="text-lg leading-relaxed text-gray-700">{{ $project->summary }}</p>

        @if ($project->stack_items)
            <ul role="list" class="mt-6 flex flex-wrap gap-2">
                @foreach ($project->stack_items as $item)
                    <li class="rounded bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700">{{ $item }}</li>
                @endforeach
            </ul>
        @endif

        @if ($project->repo_url || $project->demo_url)
            <div class="mt-6 flex flex-wrap gap-3">
                @if ($project->repo_url)
                    <a href="{{ $project->repo_url }}" rel="noopener noreferrer" class="text-sm font-medium text-brand-600 hover:text-brand-700">
                        Source code &rarr;
                    </a>
                @endif

                @if ($project->demo_url)
                    <a href="{{ $project->demo_url }}" rel="noopener noreferrer" class="text-sm font-medium text-brand-600 hover:text-brand-700">
                        Live demo &rarr;
                    </a>
                @endif
            </div>
        @endif

        <section class="mt-12" aria-labelledby="milestones-heading">
            <div class="flex items-baseline justify-between gap-4">
                <h2 id="milestones-heading" class="text-xl font-semibold tracking-tight text-gray-900">
                    Milestones
                </h2>

                {{-- Updated in place by the AJAX handler in resources/js/app.js. --}}
                <p id="milestone-progress" class="text-sm font-medium text-gray-500">
                    {{ $project->progressLabel() }}
                </p>
            </div>

            @if ($project->milestones->isEmpty())
                <p class="mt-4 rounded-lg border border-dashed border-gray-300 p-6 text-center text-gray-500">
                    No milestones yet for this project.
                </p>
            @else
                <ul role="list" id="milestones" class="mt-4 divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                    @foreach ($project->milestones as $milestone)
                        <li
                            class="milestone flex items-start gap-3 p-4 @if ($milestone->is_complete) is-complete @endif"
                            @auth data-toggle-url="{{ route('projects.milestones.update', [$project, $milestone]) }}" @endauth
                        >
                            @auth
                                {{--
                                    A real form, so the toggle works without JS: submitting it
                                    PATCHes the same route and the controller redirects back.
                                    app.js removes the submit button and takes over the change
                                    event, which is the only difference when JS is on.
                                --}}
                                <form
                                    method="POST"
                                    action="{{ route('projects.milestones.update', [$project, $milestone]) }}"
                                    class="flex flex-1 items-start gap-3"
                                >
                                    @csrf
                                    @method('PATCH')

                                    {{-- Unchecking sends nothing, so this supplies the "0". --}}
                                    <input type="hidden" name="is_complete" value="0">

                                    <input
                                        type="checkbox"
                                        id="milestone-{{ $milestone->id }}"
                                        name="is_complete"
                                        value="1"
                                        class="milestone__checkbox mt-0.5 rounded border-gray-300 text-brand-600"
                                        @checked($milestone->is_complete)
                                    >

                                    <span class="flex-1">
                                        <label for="milestone-{{ $milestone->id }}" class="milestone__title block text-sm font-medium text-gray-900">
                                            {{ $milestone->title }}
                                        </label>

                                        @if ($milestone->notes)
                                            <span class="mt-1 block text-sm text-gray-500">{{ $milestone->notes }}</span>
                                        @endif

                                        @if ($milestone->is_complete)
                                            <span class="mt-1 block text-xs text-gray-400">
                                                Completed {{ $milestone->completed_at->diffForHumans() }}
                                            </span>
                                        @endif
                                    </span>

                                    <button type="submit" class="milestone__submit rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50">
                                        Save
                                    </button>
                                </form>
                            @else
                                {{-- Read-only for guests: the state is shown, not editable. --}}
                                <span
                                    class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border {{ $milestone->is_complete ? 'border-brand-600 bg-brand-600 text-white' : 'border-gray-300' }}"
                                    aria-hidden="true"
                                >
                                    @if ($milestone->is_complete)
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    @endif
                                </span>

                                <span class="flex-1">
                                    <span class="milestone__title block text-sm font-medium text-gray-900">
                                        {{ $milestone->title }}
                                        <span class="sr-only">({{ $milestone->is_complete ? 'complete' : 'not complete' }})</span>
                                    </span>

                                    @if ($milestone->notes)
                                        <span class="mt-1 block text-sm text-gray-500">{{ $milestone->notes }}</span>
                                    @endif
                                </span>
                            @endauth
                        </li>
                    @endforeach
                </ul>

                @guest
                    <p class="mt-3 text-sm text-gray-500">
                        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700">Log in</a>
                        to update milestones.
                    </p>
                @endguest
            @endif
        </section>

        @if ($project->description && $project->description !== $project->summary)
            <section class="mt-12" aria-labelledby="notes-heading">
                <h2 id="notes-heading" class="text-xl font-semibold tracking-tight text-gray-900">Notes</h2>

                <div class="mt-4 space-y-4 text-gray-700">
                    @foreach (preg_split('/\R{2,}/', $project->description) as $paragraph)
                        <p class="leading-relaxed">{{ $paragraph }}</p>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
