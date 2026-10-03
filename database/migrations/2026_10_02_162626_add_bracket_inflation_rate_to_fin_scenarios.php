<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate a scenario assumes the tax tables rise by each year: every bracket
 * threshold and the standard deduction, federal, capital gains, state and
 * local alike. Null follows the profile's inflation rate, which is what every
 * projection used before a scenario could say otherwise.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_scenarios', function (Blueprint $table) {
            $table->decimal('bracket_inflation_rate', 6, 3)->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_scenarios', function (Blueprint $table) {
            $table->dropColumn('bracket_inflation_rate');
        });
    }
};
