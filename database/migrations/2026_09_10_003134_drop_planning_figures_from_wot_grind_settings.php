<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the two planning figures, and the Planning form with them.
 *
 * `garage_slots_vacant` was written and never read: no board, card or filter
 * ever asked what it said. `credits_available` had one reader — the shortfall
 * under the Credits needed card — measuring the whole tech tree against a
 * budget typed by hand, which is a comparison that stopped meaning much once
 * that card started billing the entire tree rather than a handful of targets.
 *
 * The table stays: the four filter columns beside these are the rest of it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->dropColumn(['credits_available', 'garage_slots_vacant']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores the columns at their defaults, not their contents.
     */
    public function down(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('credits_available')->default(0);
            $table->unsignedSmallInteger('garage_slots_vacant')->default(0);
        });
    }
};
