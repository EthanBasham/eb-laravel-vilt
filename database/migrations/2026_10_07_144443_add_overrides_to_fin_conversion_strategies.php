<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The years of a conversion strategy set by hand. The strategy's kind still
 * decides every other year; a year listed here converts the amount given,
 * takes the tax given out of the converted money, or both.
 *
 * Keyed by year, as a scenario's pinned years are:
 * `{"2031": {"conversion": 50000, "withheld": null}}`, each figure in today's
 * dollars and a null one left to the strategy. Null is no year set by hand.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->json('overrides')->nullable()->after('tax_outside_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->dropColumn('overrides');
        });
    }
};
