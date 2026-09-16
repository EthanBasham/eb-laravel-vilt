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
        'boosted_1' => '1-Skill Boosted Crew',
        'boosted_2' => '2-Skill Boosted Crew',
        'boosted_3' => '3-Skill Boosted Crew',
        'boosted_4' => '4-Skill Boosted Crew',
        'boosted_5' => '5-Skill Boosted Crew',
        'boosted_6' => '6-Skill Boosted Crew',
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

    /*
     * Carrying a letter as well as a name, like the crew roles above: the
     * roster row has space for a two-way switch and not for two words.
     */
    'crew_genders' => [
        'male' => ['name' => 'Male', 'letter' => 'M'],
        'female' => ['name' => 'Female', 'letter' => 'F'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blueprint fragment costs
    |--------------------------------------------------------------------------
    |
    | What one fragment of a vehicle's blueprint costs to craft, how many of
    | them complete it, and how much of the vehicle's research XP each one
    | removes. Worked out by hand from the game's own blueprint screen: the
    | encyclopedia publishes none of it, and `encyclopedia/personalmissions` is
    | the only mission or progression table it does publish.
    |
    | This reverses the decision recorded on 2026-09-09, that blueprint
    | discounts are entered and never computed. The curve is known now. The
    | figure a player transcribes into `wot_tank_purchases.research_xp` is still
    | kept, and the derived one is shown beside it rather than replacing it.
    |
    | 'national', 'group' and 'universal' are alternatives, not a total: one
    | fragment is crafted from own-nation blueprints, OR from another nation in
    | the same group at six to one, OR from universal ones, and a blueprint may
    | mix sources fragment by fragment.
    |
    | 'percent' is the share of base research XP a fragment removes — every
    | fragment EXCEPT the last, which covers whatever remains and lands the
    | vehicle on zero. Tier X is eleven fragments at 7% and then 23%, not twelve
    | at 7%. Nothing here claims to know how the game client rounds.
    |
    | 'group' is 'national' times six at every tier today. It is spelled out
    | anyway, for the reason the crew XP block gives: these are recorded as
    | given, so a rebalance that breaks the relation stays a config change.
    |
    | Tiers II-X only. A tier I is researched from nothing and a tier XI sits
    | above where the system stops, which is the range BlueprintBoard's
    | MIN_TIER and MAX_TIER already draw.
    |
    */

    'blueprint_costs' => [
        2 => ['national' => 1, 'group' => 6, 'universal' => 4, 'fragments' => 4, 'percent' => 25],
        3 => ['national' => 1, 'group' => 6, 'universal' => 4, 'fragments' => 4, 'percent' => 20],
        4 => ['national' => 1, 'group' => 6, 'universal' => 4, 'fragments' => 4, 'percent' => 18],
        5 => ['national' => 2, 'group' => 12, 'universal' => 6, 'fragments' => 6, 'percent' => 15],
        6 => ['national' => 2, 'group' => 12, 'universal' => 6, 'fragments' => 6, 'percent' => 15],
        7 => ['national' => 3, 'group' => 18, 'universal' => 8, 'fragments' => 8, 'percent' => 12],
        8 => ['national' => 3, 'group' => 18, 'universal' => 10, 'fragments' => 8, 'percent' => 12],
        9 => ['national' => 3, 'group' => 18, 'universal' => 12, 'fragments' => 10, 'percent' => 9],
        10 => ['national' => 4, 'group' => 24, 'universal' => 12, 'fragments' => 12, 'percent' => 7],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nation groups
    |--------------------------------------------------------------------------
    |
    | The game's own groupings, and the only reason they matter here: a national
    | blueprint can be spent on a vehicle of another nation in the same group,
    | at the six-to-one rate in the table above. A blueprint never leaves its
    | group.
    |
    | Every slug in 'nations' appears in exactly one group, and no group holds a
    | nation that list does not.
    |
    */

    'nation_groups' => [
        'alliance' => ['name' => 'Alliance', 'nations' => ['usa', 'uk', 'poland']],
        'bloc' => ['name' => 'Bloc', 'nations' => ['germany', 'japan']],
        'union' => ['name' => 'Union', 'nations' => ['ussr', 'china']],
        'coalition' => ['name' => 'Coalition', 'nations' => ['france', 'czech', 'italy', 'sweden']],
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
