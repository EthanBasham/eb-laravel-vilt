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
        // A grind the player has declared: "I am playing this tank towards that
        // unlock."
        //
        // The declaration is necessary rather than inferrable. Wargaming's API
        // exposes no per-vehicle unspent XP balance and no list of researched
        // modules, so the application cannot know what is already banked or
        // what is left to unlock. What it *can* do is record the vehicle's
        // lifetime XP at the moment the grind starts and measure forward from
        // there — which is what baseline_xp is.
        Schema::create('wot_grinds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            // The vehicle being played to earn the XP.
            $table->unsignedBigInteger('tank_id');

            // 'tank' for a vehicle unlock, 'module' for a gun/turret/engine.
            // Both come from the same encyclopedia sync.
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');

            // Denormalised so the list renders without reaching into the
            // modules_tree JSON, and so a grind still reads correctly if
            // Wargaming renames or removes the target later.
            $table->string('target_name');
            $table->unsignedInteger('target_xp');

            // The vehicle's lifetime XP when the grind was declared. Progress is
            // current lifetime XP minus this.
            $table->unsignedBigInteger('baseline_xp');

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // One grind per target per account — re-declaring the same one
            // should update the existing row, not stack duplicates.
            $table->unique(['wot_account_id', 'tank_id', 'target_type', 'target_id'], 'wot_grinds_unique_target');

            // The list query: this account's grinds, unfinished ones first.
            $table->index(['wot_account_id', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_grinds');
    }
};
