<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The vehicle encyclopedia only changes when Wargaming ships a game patch, so
// a weekly refresh is ample. Without it, vehicles added in a patch have no
// local row and their stats rows get dropped from the garage table.
Schedule::command('wot:sync-vehicles')->weeklyOn(1, '04:00');

// The only source of period statistics. The Wargaming API reports lifetime
// totals and nothing else, so "last 7 days" can only ever be the difference
// between two captures — meaning this history accrues forward from the first
// run and cannot be backfilled. Hourly gives period boundaries accurate to the
// hour and a usable "last 1000 battles"; a capture that finds no new battles
// writes nothing, so the cost is one API call per linked account.
Schedule::command('wot:snapshot')->hourly()->withoutOverlapping();

// XVM regenerates expected values from server-wide statistics periodically.
// Stale values skew every WN8 on the site, so this tracks them weekly.
Schedule::command('wot:sync-expected-values')->weeklyOn(1, '03:30');

// News feeds are cheap to poll; article bodies are one request each against
// someone else's server, so the command caps how many it fetches per run and
// picks up the rest next time. Three passes a day spread across working hours,
// so an article published mid-morning is on the site the same day.
//
// The timezone is pinned explicitly rather than inherited from app.timezone:
// these are wall-clock times someone chose to suit a working day, so they
// should stay put if the app's own timezone ever moves. The DST caveat that
// comes with a non-UTC schedule applies — on the spring-forward day a 02:00
// task would be skipped and on fall-back it would repeat, which is why none of
// these three sit near 02:00.
Schedule::command('wot:sync-news')
    ->cron('0 7,12,17 * * *')
    ->timezone('America/Chicago')
    ->withoutOverlapping();
