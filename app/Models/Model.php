<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The base class for this app's models: shared helpers go here.
 *
 * Every model in App\Models extends it except User, which has to extend
 * Authenticatable instead. A helper User also needs belongs in a trait used
 * both here and on User. tests/Unit/ArchTest.php enforces the rule.
 */
abstract class Model extends EloquentModel
{
    /**
     * Parses a date bound for the date scopes below.
     *
     * Throws rather than falling back, unlike carbonify() on its own: a bound
     * that quietly became "no bound" would widen a filter instead of narrowing
     * it. Only an actual null means open-ended, and callers check for that
     * before getting here.
     */
    private function dateBound(mixed $date): Carbon
    {
        return carbonify($date, fn () => throw new InvalidArgumentException('Unreadable date bound: '.var_export($date, true)));
    }
    private function compareDate(Builder $query, string $column, string $operator, Carbon $bound, bool $orNull): Builder
    {
        $column = $query->qualifyColumn($column);

        if ($orNull) {
            return $query->where(fn (Builder $inner) => $inner->where($column, $operator, $bound)->orWhereNull($column));
        }

        return $query->where($column, $operator, $bound);
    }

    // Scopes

    /**
     * Rows whose $column is at or after $date, to the second. Defaults to now.
     *
     * The column is qualified with the model's table, so this stays unambiguous
     * under a join (withPinnedFor() puts a second created_at on the query).
     */
    public function scopeOnlyOnOrAfter(Builder $query, string $column, mixed $date = null, bool $orNull = false): Builder
    {
        return $this->compareDate($query, $column, '>=', $date === null ? now() : $this->dateBound($date), $orNull);
    }

    /**
     * Rows whose $column is at or before $date, to the second. Defaults to now.
     */
    public function scopeOnlyOnOrBefore(Builder $query, string $column, mixed $date = null, bool $orNull = false): Builder
    {
        return $this->compareDate($query, $column, '<=', $date === null ? now() : $this->dateBound($date), $orNull);
    }

    /**
     * Rows whose $column falls on a day from $from to $to, both inclusive:
     * $from is taken from the start of its day and $to to the end of its day,
     * in app time. A null bound leaves that end open; with neither, the query
     * is returned untouched.
     *
     * $orNull adds rows with no value in $column, whichever bounds are given.
     */
    public function scopeOnlyWithinDays(Builder $query, string $column, mixed $from = null, mixed $to = null, bool $orNull = false): Builder
    {
        if ($from === null && $to === null) {
            return $query;
        }

        $column = $query->qualifyColumn($column);
        $start = $from === null ? null : $this->dateBound($from)->startOfDay();
        $end = $to === null ? null : $this->dateBound($to)->endOfDay();

        return $query->where(fn (Builder $outer) => $outer
            ->where(fn (Builder $range) => $range
                ->when($start, fn (Builder $bounded) => $bounded->where($column, '>=', $start))
                ->when($end, fn (Builder $bounded) => $bounded->where($column, '<=', $end)))
            ->when($orNull, fn (Builder $withNulls) => $withNulls->orWhereNull($column)));
    }

    /**
     * Rows whose span, from $startColumn to $endColumn, overlaps the days from
     * $from to $to. Days are whole and in app time, as in onlyWithinDays(), and
     * a null bound leaves that end open.
     *
     * One overlap test covers every case (starts inside, ends inside, covers
     * the whole range): the row starts before the range ends, and ends after
     * it starts.
     *
     * A null $endColumn means a single moment by default, so the row's start
     * stands in for its end. Pass $openEnded for data where a missing end means
     * still running. The two differ for a row that starts before the range:
     * a moment is over, an open-ended row is not.
     */
    public function scopeOnlyOverlappingDays(
        Builder $query,
        string $startColumn,
        string $endColumn,
        mixed $from = null,
        mixed $to = null,
        bool $openEnded = false,
    ): Builder {
        $startColumn = $query->qualifyColumn($startColumn);
        $endColumn = $query->qualifyColumn($endColumn);
        $start = $from === null ? null : $this->dateBound($from)->startOfDay();
        $end = $to === null ? null : $this->dateBound($to)->endOfDay();

        return $query
            ->when($end, fn (Builder $startsInTime) => $startsInTime->where($startColumn, '<=', $end))
            ->when($start, fn (Builder $endsInTime) => $endsInTime->where(fn (Builder $ends) => $ends
                ->where($endColumn, '>=', $start)
                ->orWhere(fn (Builder $noEnd) => $noEnd
                    ->whereNull($endColumn)
                    ->unless($openEnded, fn (Builder $moment) => $moment->where($startColumn, '>=', $start)))));
    }
}
