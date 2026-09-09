<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The grinding board.
 *
 * Modelled from a five-sheet spreadsheet, but those five sheets are one dataset
 * viewed five ways rather than five datasets: a target vehicle has a research
 * path, and each step of that path carries module XP, a blueprint-discounted
 * research cost, a Free XP intent and a credit price. "Active Grinding" is just
 * the steps currently being played.
 *
 * So: one target, many ordered steps, and every manual number lives on a step.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wot_grind_targets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            // The vehicle being worked towards — usually a tier X.
            $table->unsignedBigInteger('tank_id');

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Tracking the same target twice is a mistake, not a second plan.
            $table->unique(['wot_account_id', 'tank_id']);
            $table->index(['wot_account_id', 'completed_at']);
        });

        Schema::create('wot_grind_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_grind_target_id')->constrained()->cascadeOnDelete();

            // The vehicle you play at this stage — not the one it unlocks.
            $table->unsignedBigInteger('tank_id');
            $table->unsignedTinyInteger('tier');

            // Position along the path, lowest tier first.
            $table->unsignedTinyInteger('position');

            // What the next vehicle costs at full price, straight from the API.
            // Null on the final step, which unlocks nothing further.
            $table->unsignedInteger('research_xp')->nullable();

            /*
             * What is actually still needed, after blueprint fragments.
             *
             * Entered by hand rather than derived: Wargaming does not publish
             * the fragments-to-discount curve, so computing it would mean
             * reverse-engineering a formula that silently rots when they
             * rebalance. The game already shows the real number.
             */
            $table->unsignedInteger('research_xp_remaining')->nullable();

            // Modules still to research on this vehicle — the sheet's "XP to
            // Max", zero once a tank is elite.
            $table->unsignedInteger('module_xp_remaining')->default(0);

            // XP banked on this vehicle and not yet spent. The one number the
            // Wargaming API cannot supply at all, which is why this board is
            // manual in the first place.
            $table->unsignedInteger('banked_xp')->default(0);

            // Free XP earmarked for this step.
            $table->unsignedInteger('free_xp_planned')->default(0);

            // Blueprint fragments held for the vehicle this step unlocks.
            // Reference only — the discount is already baked into
            // research_xp_remaining.
            $table->unsignedSmallInteger('blueprint_fragments')->default(0);

            // Credit price of the vehicle this step unlocks, from the API.
            $table->unsignedBigInteger('price_credit')->nullable();

            // Whether this is one of the tanks currently being played.
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->unique(['wot_grind_target_id', 'position']);
            // Drives the Active Grinding view.
            $table->index('is_active');
        });

        // Two numbers that belong to the whole board rather than any target.
        Schema::create('wot_grind_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->unique()->constrained()->cascadeOnDelete();

            // Credits earmarked for buying vehicles. Not the account's live
            // balance — the spreadsheet tracked a planning figure well above it.
            $table->unsignedBigInteger('credits_available')->default(0);
            $table->unsignedSmallInteger('garage_slots_vacant')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_grind_settings');
        Schema::dropIfExists('wot_grind_steps');
        Schema::dropIfExists('wot_grind_targets');
    }
};
