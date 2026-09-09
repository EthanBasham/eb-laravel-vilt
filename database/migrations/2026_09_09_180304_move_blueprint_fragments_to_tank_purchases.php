<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprint fragments, per vehicle rather than per grind step.
 *
 * The last of the four figures to make this move. Fragments are held against a
 * tank whether or not a plan was ever written down for the line it sits on, and
 * they belong beside the other things this table records about acquiring that
 * tank: what it costs in credits, what it costs in XP after those fragments,
 * and whether it has been researched or bought.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            // Reference only, as it was on the step: Wargaming publishes no
            // fragments-to-discount curve, so this records what you hold and
            // research_xp records what the game says it now costs.
            $table->unsignedSmallInteger('blueprint_fragments')->default(0);
        });

        Schema::table('wot_grind_steps', function (Blueprint $table) {
            $table->dropColumn('blueprint_fragments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_grind_steps', function (Blueprint $table) {
            $table->unsignedSmallInteger('blueprint_fragments')->default(0);
        });

        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->dropColumn('blueprint_fragments');
        });
    }
};
