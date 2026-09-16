<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default bookmarks
    |--------------------------------------------------------------------------
    |
    | The community sites every player ends up with open in a second tab, shown
    | as a strip under the World of Tanks header.
    |
    | This is the *starting* list, not the live one: bookmarks become per-user
    | and editable, at which point a new account's rows are seeded from here and
    | this config stops being read for anyone who has customised theirs. Keeping
    | it in config rather than a seeder means the starting set can change
    | without a migration, and means the bar renders before any of that exists.
    |
    | `label` is what the bar prints — keep it short, the strip is one line.
    | `title` is the hover tooltip and is what explains the site.
    |
    */

    'defaults' => [
        [
            'label' => 'World of Tanks',
            'url' => 'https://worldoftanks.com/en/',
            'title' => 'The official North American portal — news, specials and the premium shop',
        ],
        [
            'label' => 'Tanks.gg',
            'url' => 'https://tanks.gg/',
            'title' => 'Vehicle specifications and the 3D armour viewer, side by side',
        ],
        [
            'label' => 'Tomato.gg',
            'url' => 'https://tomato.gg/',
            'title' => 'Stat tracker — WN8, win rate, Marks of Excellence and per-tank averages',
        ],
        [
            'label' => 'skill4ltu Index',
            'url' => 'https://skill4ltu.eu/',
            'title' => 'Per-tank equipment, crew skill and field-modification loadouts',
        ],
        [
            'label' => 'WoTInspector',
            'url' => 'https://wotinspector.com/',
            'title' => 'Replay analysis and armour inspection',
        ],
        [
            'label' => 'WoT Record',
            'url' => 'https://wot-record.com/',
            'title' => 'Replay hosting and search — other players\' battles in the tank you are grinding',
        ],
        [
            'label' => 'Wiki',
            'url' => 'https://wiki.wargaming.net/en/World_of_Tanks',
            'title' => "Wargaming's own wiki — tech trees, mechanics and vehicle histories",
        ],
        [
            'label' => 'Reddit',
            'url' => 'https://www.reddit.com/r/WorldofTanks/',
            'title' => 'r/WorldofTanks — the largest English-language community',
        ],
        [
            'label' => 'WG Mods',
            'url' => 'https://wgmods.net/',
            'title' => "Wargaming's own mod portal — the only place mods are vetted before release",
        ],
        [
            'label' => "Aslain's Modpack",
            'url' => 'https://aslain.com/',
            'title' => 'The community modpack installer — one download, mods picked from a checklist',
        ],
    ],

];
