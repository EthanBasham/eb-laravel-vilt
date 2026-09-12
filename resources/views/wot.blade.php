{{--
    Root view for the World of Tanks sub-project — the only page Inertia renders
    into. Deliberately separate from layouts/app.blade.php: that one is the Blade
    + jQuery site shell, and this one loads the Vue/Inertia bundle instead, so
    the two stacks never ship to the same page.

    Registered via Inertia::setRootView('wot') in AppServiceProvider.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name') }}</title>

        {{--
            Scoped to this sub-project on purpose. The base site has no <link
            rel="icon"> at all and gets the root /favicon.ico, which browsers
            fetch blind and which cannot be limited to a path. Declaring icons
            here overrides that for /wot only, and the base icon comes back on
            the way out — leaving the SPA is a real page load.

            The SVG carries type so browsers too old for it skip to the PNG.
            The Apple icon is pre-composited over --color-wot-abyss: iOS paints
            transparency black, which would lose the tank's own outline.
        --}}
        <link rel="icon" href="/images/wot/favicon/favicon-32.png" sizes="32x32" type="image/png">
        <link rel="icon" href="/images/wot/favicon/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/images/wot/favicon/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/sass/app.scss', 'resources/js/wot/app.js'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased text-gray-900">
        @inertia
    </body>
</html>
