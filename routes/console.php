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
