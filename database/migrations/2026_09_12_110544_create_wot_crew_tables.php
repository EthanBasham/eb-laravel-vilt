<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the player has said about the crew sitting in each vehicle.
 *
 * None of this comes from the API. The public endpoints expose crew roles and
 * the skills attached to them, and nothing whatever about a given player's
 * tankmen — no names, no training level, no skills learned. Every figure here
 * is entered by hand, like banked XP on the grinding boards.
 *
 * Two tables rather than one JSON column: a member carries four typed facts,
 * and the board totals banked XP across the whole account.
 *
 * A row exists only once something has been said about the tank. No row at all
 * is the red state on the board — no crew in this vehicle — which is why the
 * editor deletes rather than zeroing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wot_tank_crews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            // Not a foreign key, matching every other per-tank table here: the
            // encyclopedia owns wot_vehicles and a sync replaces its rows.
            $table->unsignedBigInteger('tank_id');

            /*
             * Whether the set is trained the way it wants to be — the one fact
             * here that belongs to the crew as a whole rather than to a member.
             * False by default, so an unmarked crew reads as unbalanced and
             * renders italic until it is vouched for.
             */
            $table->boolean('is_balanced')->default(false);

            $table->timestamps();

            $table->unique(['wot_account_id', 'tank_id']);
        });

        Schema::create('wot_crew_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_tank_crew_id')->constrained()->cascadeOnDelete();

            /*
             * Position in the vehicle's own crew list, which is what identifies
             * a member: `member_id` repeats within a vehicle (two loaders on an
             * IS-7), so the role cannot be the key. The role itself is not
             * stored at all — wot_vehicles.crew is the one description of what
             * a vehicle's slots are, and a copy here could disagree with it
             * after a patch moves a tank's crew around.
             */
            $table->unsignedTinyInteger('slot');

            /*
             * How many steps of XP have been zeroed out on this member: 0 for
             * an ordinary crew member, 1 or 2 for a zero-skill one. Any value
             * above zero is what makes a member "zero-skill", and the mix of
             * these across a set is what colours the cell.
             */
            $table->unsignedTinyInteger('zero_skills')->default(0);

            // Skills trained. 0 is a real state — trained to 100% with nothing
            // on top — and is why the progression table starts at Base.
            $table->unsignedTinyInteger('skill_level')->default(0);

            $table->boolean('is_max')->default(false);

            // XP earned and not yet spent, per member rather than per set: a
            // loader recruited late is genuinely behind the commander beside
            // them.
            $table->unsignedBigInteger('banked_xp')->default(0);

            $table->timestamps();

            $table->unique(['wot_tank_crew_id', 'slot']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_crew_members');
        Schema::dropIfExists('wot_tank_crews');
    }
};
