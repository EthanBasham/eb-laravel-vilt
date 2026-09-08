{{--
    The one shared site layout. Public pages and the authenticated Breeze pages
    both render through it, so the header and footer are identical everywhere.

    Slots: `$header` is Breeze's optional page-heading band; `$title` sets the
    document title. Both are optional.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' · '.config('app.name') : config('app.name') }}</title>
        <meta name="description" content="{{ $description ?? 'A hub for VILT stack sub-projects — Vue, Inertia, Laravel and Tailwind, one experiment at a time.' }}">

        {{-- Self-hosted Instrument Sans, emitted by laravel-vite-plugin's font helper. --}}
        @fonts

        {{-- Three entrypoints: Tailwind utilities, the custom SASS layer, then jQuery. --}}
        @vite(['resources/css/app.css', 'resources/sass/app.scss', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased text-gray-900">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-brand-700 focus:shadow-lg">
            Skip to main content
        </a>

        <div class="flex min-h-screen flex-col bg-gray-50">
            @include('partials.header')

            @isset($header)
                <div class="border-b border-gray-200 bg-white">
                    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </div>
            @endisset

            <main id="main" class="flex-1">
                {{ $slot }}
            </main>

            @include('partials.footer')
        </div>
    </body>
</html>
