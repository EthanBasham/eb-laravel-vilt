<?php

use Illuminate\Support\Carbon;

it('returns the default for empty input without parsing it', function (mixed $empty) {
    expect(carbonify($empty, 'fallback'))->toBe('fallback')
        ->and(carbonify($empty))->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

it('parses a date string into a Carbon', function () {
    expect(carbonify('2026-09-07 14:30:00')->toDateTimeString())->toBe('2026-09-07 14:30:00');
});

it('returns the default for input it cannot parse', function (mixed $unparseable) {
    expect(carbonify($unparseable, 'fallback'))->toBe('fallback')
        ->and(carbonify($unparseable))->toBeNull();
})->with([
    'garbage' => ['not a date'],
    'month 13' => ['2026-13'],
    'an array' => [['2026-09']],
]);

it('only runs a closure default when the default is used', function () {
    $calls = 0;
    $default = function () use (&$calls): string {
        $calls++;

        return 'fallback';
    };

    carbonify('2026-09-07', $default);
    $fallback = carbonify('not a date', $default);

    expect($fallback)->toBe('fallback')
        ->and($calls)->toBe(1);
});

it('converts a string with its own offset to app time', function () {
    config(['app.timezone' => 'America/Chicago']);

    $parsed = carbonify('2026-09-07T09:00:00+00:00');

    expect($parsed->timezoneName)->toBe('America/Chicago')
        ->and($parsed->toDateTimeString())->toBe('2026-09-07 04:00:00');
});

it('reads a year-month as the 1st of that month, whatever today is', function () {
    Carbon::setTestNow('2026-08-31 10:00:00');

    expect(carbonify('2026-09')->toDateString())->toBe('2026-09-01');
});
