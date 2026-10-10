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
    | Crew perks
    |--------------------------------------------------------------------------
    |
    | Every skill and perk a tanker can train, and which roles can train it —
    | taken from encyclopedia/crewskills and encyclopedia/crewroles on
    | 2026-10-10 and kept here rather than fetched: it changes with a patch,
    | not with a player, and the Guide tab only needs to name and draw them.
    | Each one's icon is public/images/crew-perks/{key}.png, the API's own
    | 52px "big" icon mirrored locally because its URL carries a version
    | (static/2.77.0/…) that stops resolving on a later deploy.
    |
    | The descriptions are not the API's, which run to a few words each and
    | are missing for eleven perks. They are the wording of Wargaming's own
    | article "Crew Rework Complete: New Perks and Final Improvements"
    | (worldoftanks.com/news/general-news/crew-perks-expansion-2026/), read
    | 2026-10-10, with every figure "for perks trained to 100%" as it says.
    | Sixth Sense is the one perk that article does not describe, so it keeps
    | the API's sentence. Firefighting's name is also not the API's, which
    | gives none.
    |
    | crew_role_perks is in the order the encyclopedia lists them for the
    | role, which puts the three or four every role shares first.
    |
    */

    'crew_perks' => [
        'repair' => ['name' => 'Repairs', 'description' => 'When fully trained for all crew members, increases the repair speed of the vehicle\'s damaged modules by 80%.'],
        'camouflage' => ['name' => 'Concealment', 'description' => 'When fully trained for all crew members, increases vehicle concealment by 80%.'],
        'brotherhood' => ['name' => 'Brothers in Arms', 'description' => 'When fully trained for all crew members, increases the crew efficiency bonus of the entire crew by 5%.'],
        'commander_tutor' => ['name' => 'Mentor', 'description' => 'Increases the amount of XP earned by 20% for all crew members. Enables the Commander to replace knocked-out members with 65% effectiveness.'],
        'commander_eagleEye' => ['name' => 'Recon', 'description' => 'Increases view range by 2% and reduces the penalty to damaged observation devices by 20%.'],
        'commander_sixthSense' => ['name' => 'Sixth Sense', 'description' => 'Enables the Commander to identify whether their vehicle has been spotted by the enemy.'],
        'commander_enemyShotPredictor' => ['name' => 'Sound Detection', 'description' => 'Issues an alert about enemy SPG fire with a 0.1 s delay and identifies the direction of the shot. Decreases the negative effect of stunning by 10%.'],
        'commander_practical' => ['name' => 'Practicality', 'description' => 'Decreases consumable cooldown time by 15%.'],
        'commander_emergency' => ['name' => 'Emergency', 'description' => 'Increases the crew efficiency bonus by 5% for 15 s after taking enemy damage. The effect does not stack up.'],
        'commander_coordination' => ['name' => 'Coordination', 'description' => 'Increases aiming speed by 12.5% for 15 s after you spot an enemy vehicle. The effect does not stack up.'],
        'commander_holdLine' => ['name' => 'Hold the Line', 'description' => 'Increases the crew efficiency bonus by 5% while the enemy team has at least three more vehicles in battle than yours.'],
        'commander_staySharp' => ['name' => 'Stay Sharp', 'description' => 'Increases the crew efficiency bonus by 5% for 15 s after using a First Aid Kit. Allows a First Aid Kit to be used even if no crew members are injured.'],
        'gunner_sniper' => ['name' => 'Deadeye', 'description' => 'Increases the chance of critically damaging enemy vehicle modules and injuring enemy crew members with all types of shells by 3%.'],
        'gunner_smoothTurret' => ['name' => 'Snap Shot', 'description' => 'Decreases gun dispersion during turret rotation by 7.5%.'],
        'gunner_rancorous' => ['name' => 'Designated Target', 'description' => 'Increases the time before an enemy vehicle is no longer visible inside the Gunner\'s viewing area by 2 s. Enables identification of damaged modules with a 0.5 s delay.'],
        'gunner_focus' => ['name' => 'Concentration', 'description' => 'Decreases the gun dispersion of a stationary vehicle by 3.5%. The effect starts 3 s after the vehicle stops.'],
        'gunner_quickAiming' => ['name' => 'Quick Aiming', 'description' => 'Increases aiming speed and turret rotation speed by 2.5%.'],
        'gunner_armorer' => ['name' => 'Armorer', 'description' => 'Reduces the range of potential damage and penetration to ±5%. Decreases gun dispersion by 1.5%.'],
        'gunner_pointBlast' => ['name' => 'Point Blank', 'description' => 'Increases maximum potential penetration by 5% when firing at enemy vehicles less than 50 m away.'],
        'gunner_loneWolf' => ['name' => 'Lone Wolf', 'description' => 'Decreases gun dispersion and increases aiming speed by 5% while there are no allied vehicles within a 300 m radius.'],
        'driver_virtuoso' => ['name' => 'Clutch Braking', 'description' => 'Increases hull traverse speed by 5%.'],
        'driver_smoothDriving' => ['name' => 'Smooth Ride', 'description' => 'Decreases gun dispersion when firing on the move by 4%.'],
        'driver_badRoadsKing' => ['name' => 'Off-Road Driving', 'description' => 'Reduces speed loss on moderately soft terrain by 5% and makes speed loss on soft terrain equal to 100% of the resulting value.'],
        'driver_rammingMaster' => ['name' => 'Controlled Impact', 'description' => 'Increases ramming damage to enemy vehicles by 20%. Reduces ramming damage to your vehicle by 25% and to your suspension by 50%.'],
        'driver_motorExpert' => ['name' => 'Engineer', 'description' => 'Increases the top forward and reverse speed of your vehicle by 1 km/h. Reduces the penalty to a damaged engine by 20%.'],
        'driver_reliablePlacement' => ['name' => 'Reliable Placement', 'description' => 'Increases HE shell damage absorption by 15%. Reduces the chance of engine fire by 15% and damage to your suspension by 15%.'],
        'driver_suspensionRepair' => ['name' => 'Field Support', 'description' => 'Increases suspension repair speed by 15% at distances of less than 50 m from an allied vehicle.'],
        'driver_bulletproof' => ['name' => 'Bulletproof', 'description' => 'Increases the crew efficiency bonus by 5% if the amount of damage you block exceeds your vehicle\'s initial hit points.'],
        'fireFighting' => ['name' => 'Firefighting', 'description' => 'Increases fire extinguishing speed by 80%.'],
        'radioman_finder' => ['name' => 'Situational Awareness', 'description' => 'Increases view range by 3%.'],
        'radioman_interference' => ['name' => 'Jamming', 'description' => 'Decreases the time your vehicle remains spotted by the enemy by 1 s.'],
        'radioman_signalInterception' => ['name' => 'Signal Interception', 'description' => 'Decreases the time to determine whether your vehicle has been spotted by the enemy by 0.75 s.'],
        'radioman_sideBySide' => ['name' => 'Side By Side', 'description' => 'Increases the crew efficiency bonus by 2.5% at distances of 50 m or less from an allied vehicle of the same type.'],
        'radioman_expert' => ['name' => 'Communications Expert', 'description' => 'Increases the crew efficiency bonus by 2.5% if the amount of damage you assist exceeds your vehicle\'s initial hit points.'],
        'radioman_battleTempered' => ['name' => 'Battle Tempered', 'description' => 'Each time the vehicle\'s crew is stunned, decreases the negative effect of stunning by 7.5%, up to a maximum of 30%.'],
        'radioman_threatSearch' => ['name' => 'Threat Search', 'description' => 'Increases view range by 2% for 5 s after receiving the Sixth Sense alert.'],
        'loader_pedant' => ['name' => 'Safe Stowage', 'description' => 'Increases ammo rack durability by 25%.'],
        'loader_desperado' => ['name' => 'Adrenaline Rush', 'description' => 'Decreases gun loading time by 5% if your vehicle has under 25% of its hit points left.'],
        'loader_intuition' => ['name' => 'Intuition', 'description' => 'Decreases the time of changing shell types in a loaded gun by 60%.'],
        'loader_perfectCharge' => ['name' => 'Perfect Charge', 'description' => 'Increases shell velocity by 10%.'],
        'loader_ammunitionImprove' => ['name' => 'Ammo Tuning', 'description' => 'Increases minimum potential damage and minimum potential penetration by 2%.'],
        'loader_melee' => ['name' => 'Close Combat', 'description' => 'Decreases gun loading time by 2.5% at distances of 50 m or less from the enemy vehicle.'],
        'loader_magMastery' => ['name' => 'Mag Mastery', 'description' => 'Decreases magazine reload time by 2.5%.'],
        'loader_secondChance' => ['name' => 'Second Chance', 'description' => 'Reduces gun loading time by 2.5% for the next shell if the previous shot did not cause damage to an enemy vehicle.'],
    ],

    'crew_role_perks' => [
        'commander' => ['repair', 'camouflage', 'brotherhood', 'commander_tutor', 'commander_eagleEye', 'commander_sixthSense', 'commander_enemyShotPredictor', 'commander_practical', 'commander_emergency', 'commander_coordination', 'commander_holdLine', 'commander_staySharp'],
        'gunner' => ['repair', 'camouflage', 'brotherhood', 'gunner_sniper', 'gunner_smoothTurret', 'gunner_rancorous', 'gunner_focus', 'gunner_quickAiming', 'gunner_armorer', 'gunner_pointBlast', 'gunner_loneWolf'],
        'driver' => ['repair', 'camouflage', 'brotherhood', 'driver_virtuoso', 'driver_smoothDriving', 'driver_badRoadsKing', 'driver_rammingMaster', 'driver_motorExpert', 'driver_reliablePlacement', 'driver_suspensionRepair', 'driver_bulletproof'],
        'radioman' => ['repair', 'fireFighting', 'camouflage', 'brotherhood', 'radioman_finder', 'radioman_interference', 'radioman_signalInterception', 'radioman_sideBySide', 'radioman_expert', 'radioman_battleTempered', 'radioman_threatSearch'],
        'loader' => ['repair', 'camouflage', 'brotherhood', 'loader_pedant', 'loader_desperado', 'loader_intuition', 'loader_perfectCharge', 'loader_ammunitionImprove', 'loader_melee', 'loader_magMastery', 'loader_secondChance'],
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
    | 'national' and 'universal' are BOTH spent on every fragment, not a choice
    | between them: one fragment costs that many national blueprints AND that
    | many universal ones. 'group' is what the national half costs when a peer
    | nation in the same group pays it instead of the vehicle's own — six to one
    | — and the universal half is unchanged by who pays. There is no fragment
    | bought with universal blueprints alone.
    |
    | This corrects what was recorded here on 2026-09-15, that the three columns
    | were alternative prices for one fragment. They are not, and a plan costed
    | that way quoted roughly a third of the true price.
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
