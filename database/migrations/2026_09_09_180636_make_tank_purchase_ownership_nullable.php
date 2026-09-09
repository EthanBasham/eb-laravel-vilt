<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Ownership becomes a tri-state, so a row can exist without claiming one.
 *
 * LineOwnership stops back-filling a vehicle the moment a purchase row exists,
 * on the grounds that an explicit statement beats the inference drawn from play
 * history. That was sound while the only way to get a row was to tick a box.
 * This table now also holds a blueprint-discounted research cost and a fragment
 * count, and typing either created a row saying nothing about ownership — which
 * silently un-owned the tank and put its unlock back on the bill.
 *
 * Null now means "not stated", the same shape researched took on
 * wot_tank_modules and for the same reason.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->boolean('is_unlocked')->nullable()->default(null)->change();
            $table->boolean('is_purchased')->nullable()->default(null)->change();
        });

        /*
         * Existing rows that never stated anything, cleared.
         *
         * Both false is unreachable from the purchase board: un-buying a tank
         * hands back the researched state, so a row it wrote with is_purchased
         * false always has is_unlocked true. Both false therefore means the row
         * was created by a research-cost or fragment write, which is exactly
         * the case this migration exists to stop mattering.
         */
        DB::table('wot_tank_purchases')
            ->where('is_purchased', false)
            ->where('is_unlocked', false)
            ->update(['is_purchased' => null, 'is_unlocked' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('wot_tank_purchases')->whereNull('is_purchased')->update(['is_purchased' => false]);
        DB::table('wot_tank_purchases')->whereNull('is_unlocked')->update(['is_unlocked' => false]);

        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->boolean('is_unlocked')->default(false)->change();
            $table->boolean('is_purchased')->default(false)->change();
        });
    }
};
