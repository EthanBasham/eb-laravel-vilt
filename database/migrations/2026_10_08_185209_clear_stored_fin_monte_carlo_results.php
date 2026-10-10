<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stored Monte Carlo results are thrown away: they were kept as what was
 * left after the heirs' tax alone, and the card now reads what can be
 * inherited, which takes the tax on the savings' gains off as well. The
 * settings stay; the next run fills the results in again.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('fin_monte_carlo_runs')->whereNotNull('results')->update(['results' => null, 'inputs_hash' => null, 'ran_at' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nothing to put back: the results are worked out again by a run.
    }
};
