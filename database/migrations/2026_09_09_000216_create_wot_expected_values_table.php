<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // XVM's WN8 expected-value table: what an average player achieves in
        // each vehicle. WN8 is a comparison against these, so they are not
        // optional trivia — without them the rating cannot be computed at all.
        //
        // Sourced from https://static.modxvm.com/wn8-data-exp/json/wn8exp.json
        // (~860 tanks, ~87 kB) by `wot:sync-expected-values`.
        Schema::create('wot_expected_values', function (Blueprint $table) {
            // Wargaming's tank_id, same natural key as wot_vehicles. Not a
            // foreign key: XVM publishes values for vehicles our encyclopedia
            // copy may not have yet, and a stale sync shouldn't reject the feed.
            $table->unsignedBigInteger('tank_id')->primary();

            // Stored as float rather than decimal: these are statistical
            // averages feeding a ratio, so precision beyond a few places is
            // meaningless and exactness is not required.
            $table->float('exp_damage');
            $table->float('exp_spot');
            $table->float('exp_frag');
            $table->float('exp_def');
            $table->float('exp_win_rate');

            // XVM's own dated version string, so it's obvious how stale the
            // table is without inspecting timestamps.
            $table->string('version')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_expected_values');
    }
};
