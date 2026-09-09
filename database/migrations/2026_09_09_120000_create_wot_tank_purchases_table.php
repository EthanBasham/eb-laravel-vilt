<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tank ownership state, keyed on the tank rather than on a grind step.
     *
     * Deliberately not a column on wot_grind_steps. A tank worth buying is not
     * always a tank on a tracked research path — and the tier XI vehicles that
     * sit above a tier X target are reachable without being part of any path,
     * so hanging their state off a step would have meant inventing steps and
     * disturbing every XP figure the board already reconciles against.
     */
    public function up(): void
    {
        Schema::create('wot_tank_purchases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tank_id');

            // Researched, but not necessarily bought.
            $table->boolean('is_unlocked')->default(false);
            $table->boolean('is_purchased')->default(false);

            /*
             * What this vehicle actually costs *you*, when that differs from
             * the encyclopedia price — the seasonal events hand out selectable
             * discounts, and a 7,400,000 tank at -50% is the number that should
             * drive the plan. Null means "use the API price", so clearing the
             * field restores the default rather than zeroing the cost.
             */
            $table->unsignedBigInteger('price_credit')->nullable();

            $table->timestamps();

            $table->unique(['wot_account_id', 'tank_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wot_tank_purchases');
    }
};
