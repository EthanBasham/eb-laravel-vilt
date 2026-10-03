<?php

/*
 * Global helper functions, autoloaded through composer.json's "files" list, so
 * they are available everywhere without an import.
 *
 * Wrap each one in a function_exists() check. Laravel and its packages define
 * their own global helpers, and redeclaring a name is a fatal error rather than
 * an override.
 */

use Illuminate\Support\Carbon;

if (! function_exists('carbonify')) {
    /**
     * Leniently turns a value into a Carbon in app time.
     *
     * Empty or unparseable input returns $default instead of throwing. It is
     * resolved through value(), so a closure default only runs when it's used.
     * Anything Carbon::parse() accepts is accepted, relative dates like
     * "tomorrow" included, so this is a forgiving parser, not a validator.
     *
     * The result is always converted to app time. A string with its own offset
     * would otherwise keep it, and a timestamp column stores the wall clock
     * with no zone — see "Time and timezones" in CLAUDE.md.
     *
     * @template TDefault
     *
     * @param  TDefault|(Closure(): TDefault)  $default
     * @return Carbon|TDefault
     */
    function carbonify(mixed $date, mixed $default = null): mixed
    {
        if (! $date) {
            return value($default);
        }

        return rescue(
            fn (): Carbon => Carbon::parse($date)->setTimezone(config('app.timezone')),
            fn (): mixed => value($default),
            report: false,
        );
    }
}
