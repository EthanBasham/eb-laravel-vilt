<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Realm
    |--------------------------------------------------------------------------
    |
    | Wargaming runs a separate, non-interchangeable realm per region: accounts,
    | account IDs and application IDs all belong to exactly one of them. This
    | project targets North America.
    |
    */

    'realm' => env('WARGAMING_REALM', 'na'),

    'hosts' => [
        'na' => 'https://api.worldoftanks.com',
        'eu' => 'https://api.worldoftanks.eu',
        'asia' => 'https://api.worldoftanks.asia',
    ],

    /*
    |--------------------------------------------------------------------------
    | Application ID
    |--------------------------------------------------------------------------
    |
    | Issued at https://developers.wargaming.net. Two application types exist and
    | the difference matters:
    |
    |   Server     — validates the calling IP against up to 5 registered
    |                addresses, 20 req/s. Returns 407 INVALID_IP_ADDRESS from
    |                anywhere else.
    |   Standalone — no IP check, 10 req/s per IP.
    |
    | This project uses a Server application, so the machine's public IP has to
    | be registered on it. A home IP that changes will start failing.
    |
    */

    'application_id' => env('WARGAMING_APPLICATION_ID'),

    /*
    |--------------------------------------------------------------------------
    | Nation order
    |--------------------------------------------------------------------------
    |
    | The order nations appear in the game's own tech tree, which is neither
    | alphabetical nor by vehicle count — so every vehicle list in the app sorts
    | by this to match what a player is used to scanning.
    |
    | Sorting happens in PHP rather than SQL: Postgres has array_position but
    | SQLite (which the test suite runs on) does not, and a CASE ladder in every
    | query would be worse than one comparator.
    |
    */

    'nation_order' => [
        'usa', 'germany', 'ussr', 'uk', 'france',
        'czech', 'japan', 'china', 'poland', 'sweden', 'italy',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    */

    'timeout' => env('WARGAMING_TIMEOUT', 10),

    'retries' => env('WARGAMING_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Cache lifetimes (seconds)
    |--------------------------------------------------------------------------
    |
    | The API is rate limited per application ID, so responses are cached rather
    | than re-fetched per page view. Player stats update once a battle ends, so
    | a few minutes is plenty; the vehicle encyclopedia only changes on game
    | patches and is stored in a table instead (see wot_vehicles).
    |
    */

    'cache' => [
        /*
         * One entry now holds all three dashboard payloads, fetched together.
         *
         * Thirty minutes rather than five: a cold load costs ~2.5s, almost all
         * of it waiting on tanks/stats, so a short TTL meant regularly paying
         * that for data that only moves when a battle ends. The Refresh control
         * on the page busts this on demand, which is what makes the longer
         * window safe — stale data is never something you're stuck with.
         */
        'dashboard' => env('WARGAMING_CACHE_DASHBOARD', 1800),
    ],

];
