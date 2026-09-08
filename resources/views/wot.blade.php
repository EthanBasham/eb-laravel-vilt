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

        @fonts
        @vite(['resources/css/app.css', 'resources/sass/app.scss', 'resources/js/wot/app.js'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased text-gray-900">
        @inertia
    </body>
</html>
