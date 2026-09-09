<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-adds the two tech-tree columns the grinding board needs.
 *
 * An earlier grind tracker stored these plus `modules_tree`, and all three went
 * when it was removed. Only these two come back: module XP is entered by hand
 * (the game's own numbers, already discounted by blueprints), so the several
 * megabytes of modules_tree JSON has no reader.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_vehicles', function (Blueprint $table) {
            // {tank_id: xp_cost} — what this vehicle unlocks and for how much.
            // Null on premiums, which sit outside the tech tree.
            $table->json('next_tanks')->nullable();

            // Credit price of the vehicle. Verified against the spreadsheet:
            // 6,100,000 for a tier X, matching every row of Tanks to Purchase.
            $table->unsignedBigInteger('price_credit')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_vehicles', function (Blueprint $table) {
            $table->dropColumn(['next_tanks', 'price_credit']);
        });
    }
};
