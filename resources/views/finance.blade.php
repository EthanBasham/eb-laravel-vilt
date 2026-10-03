{{--
    Root view for the Financial Fleet sub-project — the only page its Inertia
    app renders into. A sibling of wot.blade.php, and separate from it for the
    same reason that one is separate from layouts/app.blade.php: each island
    loads its own Vue bundle and nothing else's.

    Selected by HandleFinanceInertiaRequests::$rootView, which is applied to
    the /finance route group alone — see routes/web.php.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/finance/app.js'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
