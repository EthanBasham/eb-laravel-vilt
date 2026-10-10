<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crew guides: which perks each role should train, and in what order.
 *
 * A guide is a named set of five answers — one per crew role — so it is two
 * tables rather than a JSON column on one. The page saves a role the moment a
 * perk is dropped or a note is left, and two of those landing together must
 * not write over each other, which a single document per guide would.
 *
 * Nothing is seeded. What to train is an opinion, and the page has none of its
 * own.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wot_crew_guides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            $table->timestamps();
        });

        Schema::create('wot_crew_guide_roles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_crew_guide_id')->constrained()->cascadeOnDelete();

            // A key of config('wargaming.crew_roles').
            $table->string('role');

            /*
             * The perks to train, in the order to train them — keys of
             * config('wargaming.crew_perks'). Only the included ones are
             * stored: everything else the role can train is the excluded
             * bucket, which has no order of its own to keep, and a perk a
             * later patch adds lands there without a row being touched.
             */
            $table->json('included');

            $table->string('notes')->nullable();

            $table->timestamps();

            // A role with nothing decided has no row; one with something has
            // exactly one.
            $table->unique(['wot_crew_guide_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_crew_guide_roles');
        Schema::dropIfExists('wot_crew_guides');
    }
};
