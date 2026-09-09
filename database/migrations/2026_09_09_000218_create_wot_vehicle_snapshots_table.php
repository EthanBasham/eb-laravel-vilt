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
        // Per-vehicle totals at a point in time.
        //
        // Needed separately from wot_snapshots because recent WN8 is a
        // per-tank weighted calculation — an account-level delta cannot produce
        // it, since expected values differ per vehicle.
        //
        // Only vehicles whose battle count actually *changed* since the last
        // capture get a row. A player touches a handful of tanks a day out of
        // several hundred owned, so this is tens of rows per capture rather
        // than hundreds. Reading a vehicle's state at some past moment is then
        // "its most recent row at or before that moment", and no row at all
        // means it had not been played yet.
        Schema::create('wot_vehicle_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('tank_id');

            $table->timestamp('captured_at');
            $table->unsignedInteger('battles');

            $table->json('statistics');

            $table->timestamps();

            // Serves the per-tank "state at or before this moment" lookup that
            // every period calculation performs, and covers the FK as its
            // leftmost column.
            $table->index(['wot_account_id', 'tank_id', 'captured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_vehicle_snapshots');
    }
};
