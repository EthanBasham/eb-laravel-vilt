<x-app-layout title="Projects">
    <x-slot name="header">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Projects</h1>
        <p class="mt-1 text-sm text-gray-600">Every sub-project in the learning track.</p>
    </x-slot>

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        @if ($projects->isEmpty())
            <p class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-gray-500">
                Nothing published yet.
            </p>
        @else
            <ul role="list" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($projects as $project)
                    <li>
                        <x-project-card :project="$project" />
                    </li>
                @endforeach
            </ul>

            <div class="mt-10">
                {{ $projects->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
