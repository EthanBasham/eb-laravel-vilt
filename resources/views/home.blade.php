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
                    A base application for building small, self-contained sub-projects — each one
                    written to learn a specific piece of the stack properly rather than in the
                    abstract. This is the foundation they mount onto: Laravel on PostgreSQL, Blade
                    layouts, Vite compiling Tailwind, SASS and jQuery, and Breeze handling auth.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    @auth
                        <a
                            href="{{ route('wot.dashboard') }}"
                            class="inline-flex items-center rounded-md bg-brand-600 px-5 py-3 text-sm font-medium text-white hover:bg-brand-700"
                        >
                            World of Tanks dashboard
                        </a>

                        <a
                            href="{{ route('dashboard') }}"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Account
                        </a>
                    @else
                        <a
                            href="{{ route('register') }}"
                            class="inline-flex items-center rounded-md bg-brand-600 px-5 py-3 text-sm font-medium text-white hover:bg-brand-700"
                        >
                            Create an account
                        </a>

                        <a
                            href="{{ route('login') }}"
                            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Log in
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </section>

    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:px-8">
        <section aria-labelledby="stack-heading">
            <h2 id="stack-heading" class="text-2xl font-semibold tracking-tight text-gray-900">
                What's wired up
            </h2>

            <dl class="mt-6 space-y-4">
                @foreach ([
                    'Laravel '.Illuminate\Foundation\Application::VERSION => 'On PostgreSQL, with Breeze providing login, registration and route protection.',
                    'Blade' => 'Server-rendered throughout. Vue and Inertia arrive inside sub-projects, not here.',
                    'Tailwind CSS v4' => 'Configured from CSS — design tokens live in an @theme block, not a JS config file.',
                    'SASS' => 'A separate Vite entrypoint for anything a utility class can\'t express.',
                    'jQuery' => 'Progressive enhancement only. Everything works with JavaScript disabled.',
                    'Vue + Inertia' => 'Scoped to the World of Tanks dashboard at /wot — the first sub-project, and the first SPA.',
                ] as $name => $detail)
                    <div class="border-l-2 border-brand-200 pl-4">
                        <dt class="font-medium text-gray-900">{{ $name }}</dt>
                        <dd class="mt-1 text-sm leading-relaxed text-gray-600">{{ $detail }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{--
            Proves the front-end pipeline is connected end to end: this form is
            submitted by jQuery, with the CSRF header app.js registers, to a real
            validated endpoint, and the JSON reply is rendered without a reload.

            It is also a working plain form. With JavaScript off it posts
            normally and the controller redirects back with the result in the
            session, which is what the @session block below renders.
        --}}
        <section aria-labelledby="pipeline-heading">
            <h2 id="pipeline-heading" class="text-2xl font-semibold tracking-tight text-gray-900">
                Pipeline check
            </h2>

            <p class="mt-2 text-sm leading-relaxed text-gray-600">
                Send a message to the server and get it back. Nothing is stored — this exists to
                show Blade, jQuery, CSRF, validation and JSON all talking to each other.
            </p>

            <form
                id="pipeline-check"
                method="POST"
                action="{{ route('pipeline-check.store') }}"
                class="mt-6 rounded-lg border border-gray-200 bg-white p-6"
            >
                @csrf

                <label for="pipeline-message" class="block text-sm font-medium text-gray-700">
                    Message
                </label>

                <div class="mt-2 flex flex-wrap gap-2">
                    <input
                        type="text"
                        id="pipeline-message"
                        name="message"
                        value="{{ old('message') }}"
                        maxlength="100"
                        required
                        class="flex-1 rounded-md border-gray-300 text-sm"
                        placeholder="Hello from Blade"
                    >

                    <button
                        type="submit"
                        class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                    >
                        Send
                    </button>
                </div>

                {{--
                    Not <x-input-error>: that component renders nothing when there
                    are no messages, so jQuery would have no element to write a
                    validation error into. This one is always in the DOM, carrying
                    the server-rendered error on the no-JS path and acting as the
                    target for the 422 branch in app.js on the JS path.
                --}}
                <p
                    data-error
                    role="alert"
                    class="mt-2 text-sm text-red-600 @unless ($errors->has('message')) hidden @endunless"
                >{{ $errors->first('message') }}</p>

                {{-- aria-live so the AJAX reply is announced, not just painted. --}}
                <dl
                    id="pipeline-result"
                    class="mt-4 space-y-1 text-sm text-gray-600 @unless (session('pipeline_check')) hidden @endunless"
                    aria-live="polite"
                >
                    @php($result = session('pipeline_check'))

                    <div class="flex gap-2">
                        <dt class="font-medium text-gray-900">Reversed</dt>
                        <dd data-field="reversed" class="font-mono">{{ $result['reversed'] ?? '' }}</dd>
                    </div>

                    <div class="flex gap-2">
                        <dt class="font-medium text-gray-900">Handled by</dt>
                        <dd data-field="handled_by">{{ $result['handled_by'] ?? '' }}</dd>
                    </div>

                    <div class="flex gap-2">
                        <dt class="font-medium text-gray-900">At</dt>
                        <dd data-field="at" class="font-mono">{{ $result['at'] ?? '' }}</dd>
                    </div>
                </dl>
            </form>
        </section>
    </div>
</x-app-layout>
