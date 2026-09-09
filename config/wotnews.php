<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User agent
    |--------------------------------------------------------------------------
    |
    | Identifies this application to worldoftanks.com rather than impersonating
    | a browser, so the traffic is attributable and can be blocked by them if
    | they ever object. Put a real contact address in it before running this
    | anywhere but a personal machine.
    |
    */

    'user_agent' => env('WOTNEWS_USER_AGENT', 'eb-laravel-vilt/1.0 (personal WoT dashboard; +https://github.com/)'),

    /*
    |--------------------------------------------------------------------------
    | Categories to follow
    |--------------------------------------------------------------------------
    |
    | Each has its own feed at /en/rss/news/{category}/. The overall feed only
    | carries the 20 most recent items across everything, so following
    | categories individually is what keeps the quieter ones (live-streams has
    | six articles on the index) from being crowded out by patch notes.
    |
    */

    'categories' => [
        'general-news',
        'specials',
        'updates',
        'live-streams',
        'competitive-gaming',
        'guides-reviews',
    ],

    /*
    |--------------------------------------------------------------------------
    | Article bodies fetched per sync
    |--------------------------------------------------------------------------
    |
    | Event extraction needs the article HTML, which means a request each. This
    | caps how many are fetched in one run so a first sync doesn't arrive as a
    | burst of eighty requests; the remainder are picked up on the next run.
    |
    */

    'bodies_per_sync' => env('WOTNEWS_BODIES_PER_SYNC', 15),

];
