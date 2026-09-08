{{--
    Project summary card, shared by the home page and the listing.

    Expects the milestone counts to have been loaded with withCount(), which
    both callers do — reading $project->milestones here instead would issue a
    query per card.
--}}
@props(['project'])

<article class="flex h-full flex-col rounded-lg border border-gray-200 bg-white p-6 transition hover:border-brand-300 hover:shadow-md">
    <h3 class="text-lg font-semibold text-gray-900">
        <a href="{{ route('projects.show', $project) }}" class="hover:text-brand-700">
            {{ $project->title }}
        </a>
    </h3>

    <p class="mt-2 flex-1 text-sm leading-relaxed text-gray-600">
        {{ $project->summary }}
    </p>

    @if ($project->stack_items)
        <ul role="list" class="mt-4 flex flex-wrap gap-1.5">
            @foreach ($project->stack_items as $item)
                <li class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $item }}</li>
            @endforeach
        </ul>
    @endif

    <p class="mt-4 text-xs font-medium text-gray-500">
        @if ($project->milestones_count > 0)
            {{ $project->completed_milestones_count }} of {{ $project->milestones_count }} milestones complete
        @else
            No milestones yet
        @endif
    </p>
</article>
