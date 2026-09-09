<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two sets of modules per vehicle, not one.
 *
 * wot_module_plans held the modules a player intends to buy with Free XP. The
 * XP Remaining board needs the other half — the ones already researched — and
 * they are the same shape of thing about the same pair of keys, so they live in
 * one row rather than in a second table that would be queried alongside it on
 * every render. The table is renamed to say so.
 *
 * Also adds the blueprint-discounted research cost, on the vehicle being
 * unlocked rather than the one you play to unlock it. That mirrors
 * price_credit, which is likewise the cost *of* the row's tank — and it means
 * two lines converging on the same vehicle share one figure, which is correct:
 * blueprint fragments are held against a tank, not against a route to it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('wot_module_plans', 'wot_tank_modules');

        Schema::table('wot_tank_modules', function (Blueprint $table) {
            $table->renameColumn('module_ids', 'planned_module_ids');
        });

        Schema::table('wot_tank_modules', function (Blueprint $table) {
            // Researched, as opposed to planned. Same JSON-list shape and the
            // same reasoning: a handful of ids, read and written whole.
            $table->json('researched_module_ids')->nullable();
        });

        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            /*
             * What unlocking this vehicle actually costs after blueprints.
             *
             * Typed by hand, like the sheet it replaced: Wargaming does not
             * publish the fragments-to-discount curve, so deriving it would
             * mean reverse-engineering a formula that silently rots on the next
             * rebalance. Null means "no discount recorded" and the API's full
             * price stands — distinct from 0, which is a vehicle you have
             * fragments enough to unlock outright.
             */
            $table->unsignedInteger('research_xp')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->dropColumn('research_xp');
        });

        Schema::table('wot_tank_modules', function (Blueprint $table) {
            $table->dropColumn('researched_module_ids');
        });

        Schema::table('wot_tank_modules', function (Blueprint $table) {
            $table->renameColumn('planned_module_ids', 'module_ids');
        });

        Schema::rename('wot_tank_modules', 'wot_module_plans');
    }
};
