<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the last two grind-step facts onto the tank, and retires targets.
 *
 * The third and final move of this kind: free_xp_planned went to wot_tank_modules
 * and blueprint_fragments to wot_tank_purchases, each in one migration that adds
 * the new column and drops the old in the same up(). What is left on a step —
 * banked_xp, and the flag saying you are playing it — has no more reason to hang
 * off a research path than those did. Every board is built from the tech tree
 * now, so a path frozen at the moment a target was added is a second, staler copy
 * of what the tree already knows.
 *
 * The duplicate collapses here. A tank on two paths had two step rows and so two
 * banked-XP figures; keyed on the tank there can only be one, and the larger of
 * the two is the one to keep — banked XP only ever counts up.
 *
 * wot_grind_settings survives all this. It is created by the same migration as
 * the two tables being dropped, which is why that one stays untouched in history.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            /*
             * Membership of the Active Grinding list, which is the whole of what
             * "currently playing this" means — there is no separate tick for it.
             */
            $table->boolean('is_playing')->default(false)->after('blueprint_fragments');

            // XP earned on this vehicle and not yet spent. Still the one number
            // the Wargaming API cannot supply, which is why it is typed by hand.
            $table->unsignedBigInteger('banked_xp')->default(0)->after('is_playing');
        });

        $this->carryOverStepProgress();

        Schema::dropIfExists('wot_grind_steps');
        Schema::dropIfExists('wot_grind_targets');
    }

    /**
     * Reverse the migrations.
     *
     * The tables come back empty. Their rows cannot: the paths were derived from
     * the tech tree rather than entered, and the progress on them now lives on
     * the tanks.
     *
     * They have to come back all the same, which is the trap here. Rollback runs
     * newest first, and three migrations older than this one still reach for
     * wot_grind_steps on the way down — 180304 re-adds blueprint_fragments to
     * it, 154504 re-adds free_xp_planned, and 050232 drops researched_modules
     * off it. So this recreates the schema as it stood the moment up() ran:
     * the original columns, less the two that were already gone, plus the one
     * that had been added since. Each of those three then finds what it expects.
     */
    public function down(): void
    {
        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->dropColumn(['is_playing', 'banked_xp']);
        });

        Schema::create('wot_grind_targets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            // The vehicle being worked towards — usually a tier X.
            $table->unsignedBigInteger('tank_id');

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

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

            $table->unsignedInteger('research_xp')->nullable();
            $table->unsignedInteger('research_xp_remaining')->nullable();
            $table->unsignedInteger('module_xp_remaining')->default(0);
            $table->unsignedInteger('banked_xp')->default(0);
            $table->unsignedBigInteger('price_credit')->nullable();
            $table->boolean('is_active')->default(false);

            // Added after the table by 050232, and dropped again by its down().
            $table->json('researched_modules')->nullable();

            $table->timestamps();

            $table->unique(['wot_grind_target_id', 'position']);
            $table->index('is_active');
        });
    }

    /**
     * Folds every step's progress onto its tank.
     *
     * Aggregated in PHP rather than in SQL: max() over a boolean is not portable
     * between Postgres and the SQLite the tests run on, and this is a few dozen
     * rows on the one account that has any.
     */
    private function carryOverStepProgress(): void
    {
        $steps = DB::table('wot_grind_steps')
            ->join('wot_grind_targets', 'wot_grind_steps.wot_grind_target_id', '=', 'wot_grind_targets.id')
            ->select(
                'wot_grind_targets.wot_account_id',
                'wot_grind_steps.tank_id',
                'wot_grind_steps.banked_xp',
                'wot_grind_steps.is_active',
            )
            ->get()
            ->groupBy(fn (object $step): string => "{$step->wot_account_id}:{$step->tank_id}");

        foreach ($steps as $group) {
            $banked = (int) $group->max('banked_xp');
            $playing = $group->contains(fn (object $step): bool => (bool) $step->is_active);

            // Nothing said about the tank is nothing worth writing a row for.
            if ($banked === 0 && ! $playing) {
                continue;
            }

            $this->recordProgress((int) $group->first()->wot_account_id, (int) $group->first()->tank_id, $playing, $banked);
        }
    }

    private function recordProgress(int $accountId, int $tankId, bool $playing, int $banked): void
    {
        $values = ['is_playing' => $playing, 'banked_xp' => $banked, 'updated_at' => now()];

        $updated = DB::table('wot_tank_purchases')
            ->where('wot_account_id', $accountId)
            ->where('tank_id', $tankId)
            ->update($values);

        if ($updated === 0) {
            DB::table('wot_tank_purchases')->insert([
                ...$values,
                'wot_account_id' => $accountId,
                'tank_id' => $tankId,
                'created_at' => now(),
            ]);
        }
    }
};
