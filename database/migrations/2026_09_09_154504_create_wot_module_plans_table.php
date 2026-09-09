<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which modules the player intends to buy with Free XP.
 *
 * Free XP planning used to be a number typed onto a grind step, which tied it
 * to a tracked target and let it mean anything. It only ever meant one thing in
 * practice — "I will Free XP these modules" — so it is now that statement, and
 * the figure is the sum of what they cost.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Keyed on the vehicle rather than on a grind step, the same way
        // wot_tank_purchases is: a module worth Free XP-ing is a module on a
        // tank, whether or not a plan was ever written down for that line.
        Schema::create('wot_module_plans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tank_id');

            /*
             * Module ids planned for this vehicle.
             *
             * JSON rather than a pivot table, matching wot_grind_steps'
             * researched_modules: a vehicle has a handful of upgrade modules,
             * the set is read and written whole, and nothing queries across
             * vehicles for one module id.
             */
            $table->json('module_ids')->nullable();

            $table->timestamps();

            // One plan per vehicle; a second row would be a bug, not a second
            // opinion.
            $table->unique(['wot_account_id', 'tank_id']);
        });

        Schema::table('wot_grind_steps', function (Blueprint $table) {
            // Superseded by the table above. Nothing reads it any more, and
            // leaving it would give "Free XP planned" two sources that could
            // disagree.
            $table->dropColumn('free_xp_planned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_grind_steps', function (Blueprint $table) {
            $table->unsignedInteger('free_xp_planned')->default(0);
        });

        Schema::dropIfExists('wot_module_plans');
    }
};
