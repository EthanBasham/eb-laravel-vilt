<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a conversion strategy is in the side-by-side comparison or waiting
 * in the holding area. Only compared strategies are simulated when the page
 * loads, which is what lets someone keep dozens of them.
 *
 * Each user's first six, in the order they were made, stay compared; the
 * rest are moved to the holding area — the same rule a newly made strategy
 * follows from here on.
 */
return new class extends Migration
{
    /** How many a user keeps compared. Frozen here: a migration should not change with config. */
    private const COMPARED = 6;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->boolean('is_compared')->default(true)->after('kind');
        });

        DB::table('fin_conversion_strategies')->orderBy('id')->get(['id', 'user_id'])->groupBy('user_id')
            ->each(fn ($strategies) => DB::table('fin_conversion_strategies')
                ->whereIn('id', $strategies->skip(self::COMPARED)->pluck('id'))
                ->update(['is_compared' => false]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->dropColumn('is_compared');
        });
    }
};
