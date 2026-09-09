<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebases every stored wall clock from UTC onto the application timezone.
 *
 * The datetime columns here are `timestamp` — no zone — so a row holds a bare
 * wall clock and nothing records which zone produced it. Eloquent reads such a
 * column back as app time, so the moment APP_TIMEZONE stopped being UTC every
 * existing row began reading five hours late. This moves the stored values to
 * match, leaving the instants they represent unchanged.
 *
 * The conversion runs in Postgres rather than PHP so it is DST-correct per row:
 * `AT TIME ZONE 'UTC'` reads the value as UTC and yields a real instant, and the
 * second `AT TIME ZONE` renders that instant as local wall clock using whichever
 * offset was actually in force on that date. The articles here span 2023–2026
 * and so straddle several CST/CDT boundaries; a flat subtraction would be wrong
 * for half of them.
 */
return new class extends Migration
{
    /**
     * The zone the existing values were written in, named explicitly rather
     * than read from config: this migration describes one historical transition
     * and must keep meaning the same thing if the app's timezone moves again.
     */
    private const FROM = 'UTC';

    private const TO = 'America/Chicago';

    public function up(): void
    {
        $this->shift(self::FROM, self::TO);
    }
    public function down(): void
    {
        $this->shift(self::TO, self::FROM);
    }

    /**
     * Rewrite every zone-less timestamp column in the schema.
     *
     * Discovered from the catalog rather than listed, so a column added between
     * writing this and running it is not silently left behind in the old zone.
     */
    private function shift(string $from, string $to): void
    {
        // SQLite has no AT TIME ZONE, and a test database is created empty on
        // every run — there is no legacy data there to rebase.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns() as $table => $columns) {
            $assignments = collect($columns)
                ->map(fn (string $column): string => sprintf(
                    '"%1$s" = "%1$s" AT TIME ZONE %2$s AT TIME ZONE %3$s',
                    $column,
                    DB::getPdo()->quote($from),
                    DB::getPdo()->quote($to),
                ))
                ->implode(', ');

            DB::statement(sprintf('UPDATE "%s" SET %s', $table, $assignments));
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function columns(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.table_name, c.column_name
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_schema = c.table_schema AND t.table_name = c.table_name
            WHERE c.table_schema = current_schema()
              AND c.data_type = 'timestamp without time zone'
              AND t.table_type = 'BASE TABLE'
              AND c.table_name <> 'migrations'
            ORDER BY c.table_name, c.column_name
        SQL);

        $columns = [];

        foreach ($rows as $row) {
            $columns[$row->table_name][] = $row->column_name;
        }

        return $columns;
    }
};
