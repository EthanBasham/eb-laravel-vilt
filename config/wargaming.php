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
    | The keys double as the filenames under public/images/nations, and the
    | values are the API's own display names (encyclopedia/info.vehicle_nations),
    | used as alt text on the flags.
    |
    */

    'nations' => [
        'usa' => 'U.S.A.',
        'germany' => 'Germany',
        'ussr' => 'U.S.S.R.',
        'uk' => 'U.K.',
        'france' => 'France',
        'czech' => 'Czechoslovakia',
        'japan' => 'Japan',
        'china' => 'China',
        'poland' => 'Poland',
        'sweden' => 'Sweden',
        'italy' => 'Italy',
    ],

    /*
    |--------------------------------------------------------------------------
    | Crew roles
    |--------------------------------------------------------------------------
    |
    | The five roles the encyclopedia publishes, in the order the game lists a
    | crew — which is the order the board spells its letters in, so a cell reads
    | the same way the garage panel does.
    |
    | The letter is the whole of a crew member on the board. `name` is what the
    | encyclopedia calls the role (encyclopedia/crewroles), used in tooltips and
    | in the editor.
    |
    | Keyed by the API's own `member_id` / role slug. `radioman` is Wargaming's
    | spelling; the display name is not.
    |
    */

    'crew_roles' => [
        'commander' => ['name' => 'Commander', 'letter' => 'C'],
        'gunner' => ['name' => 'Gunner', 'letter' => 'G'],
        'driver' => ['name' => 'Driver', 'letter' => 'D'],
        'radioman' => ['name' => 'Radio Operator', 'letter' => 'R'],
        'loader' => ['name' => 'Loader', 'letter' => 'L'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Crew XP progression
    |--------------------------------------------------------------------------
    |
    | XP per step of crew training, entered by hand — the API publishes crew
    | roles and the skills attached to them, but no training costs at all.
    |
    | Key 0 is the base 100% qualification; 1-6 are the skills trained after it.
    | Each step is twice the one before, bar the rounding in the first.
    |
    | Recorded as given, with no claim about whether a figure is the cost of
    | that step alone or the running total to reach it — nothing computes
    | against these yet, and the board only lists them.
    |
    */

    'crew_xp' => [
        0 => 100_000,
        1 => 210_060,
        2 => 420_120,
        3 => 840_240,
        4 => 1_680_480,
        5 => 3_360_960,
        6 => 6_721_920,
    ],

    /*
    |--------------------------------------------------------------------------
    | Crew books
    |--------------------------------------------------------------------------
    |
    | The three classical books, each giving its XP to every member of a crew
    | set. Held per nation, plus a universal stack that spends anywhere — the
    | Books table is these three across `nations` above, with 'universal' as a
    | twelfth row.
    |
    */

    'crew_books' => [
        'booklet' => ['name' => 'Booklet', 'xp' => 20_000],
        'guide' => ['name' => 'Guide', 'xp' => 100_000],
        'manual' => ['name' => 'Manual', 'xp' => 250_000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Special training items
    |--------------------------------------------------------------------------
    |
    | Not books, and not held per nation: they sit under the universal row with
    | a single count each, in the same column the book totals land in.
    |
    */

    'crew_book_specials' => [
        'personal_training_manual' => 'Personal Training Manual',
        'mentoring_license' => 'Mentoring License',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recruits held
    |--------------------------------------------------------------------------
    |
    | The barracks, as counts rather than as people: what is worth knowing is
    | how many of each kind are waiting, not who they are. Spelled out one key
    | per row — including the six boosted tiers — so the order on the page is
    | this list's order and a new kind is one line here.
    |
    */

    'crew_recruits' => [
        'zero_skill_commanders' => 'Zero Skill Commanders',
        'zero_skill_crew' => 'Zero Skill Crew',
        'pending_zero_skill_crew' => 'Pending Zero Skill Crew',
        'boosted_1' => 'Boosted Crew — 1 skill',
        'boosted_2' => 'Boosted Crew — 2 skills',
        'boosted_3' => 'Boosted Crew — 3 skills',
        'boosted_4' => 'Boosted Crew — 4 skills',
        'boosted_5' => 'Boosted Crew — 5 skills',
        'boosted_6' => 'Boosted Crew — 6 skills',
        'empty_novelty_crew' => 'Empty Novelty Crew',
    ],

    /*
    |--------------------------------------------------------------------------
    | Battle Pass crew
    |--------------------------------------------------------------------------
    |
    | Where a named Battle Pass tanker has got to. 'uncollected' is the one that
    | is not a place — it is a reward not taken yet, which is why the list holds
    | rows for crew that are not in the barracks at all.
    |
    */

    'crew_statuses' => [
        'uncollected' => 'Uncollected',
        'in_barracks' => 'In barracks',
        'in_tank' => 'In tank',
    ],

    'crew_genders' => [
        'male' => 'Male',
        'female' => 'Female',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lowest tier worth budgeting for
    |--------------------------------------------------------------------------
    |
    | Tanks to Purchase lists everything one research step from a vehicle the
    | account has played, which at the bottom of the tree means a long tail of
    | tier II runabouts costing less than a single battle's profit. This is the
    | floor for which branch tops earn a row of their own, on both the purchase
    | and Free XP boards. Every row that clears it still shows its whole line,
    | however low that line starts.
    |
    */

    'line_min_tier' => 8,

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
