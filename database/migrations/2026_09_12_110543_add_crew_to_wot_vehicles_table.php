<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The crew a vehicle carries, from the encyclopedia.
 *
 * `encyclopedia/vehicles` publishes this as an ordered list of slots, each with
 * a `member_id` naming its primary role and a `roles` map listing every role
 * that body covers — the IS-7's fourth slot is a Loader who is also the Radio
 * Operator. Stored as sent, because both halves are read: the order is the
 * order the letters are spelled in, and the extra roles are what a tooltip
 * says.
 *
 * Note that `member_id` is not unique within a vehicle — the IS-7 has two
 * loaders — so everything keyed against a crew slot is keyed by position.
 *
 * Populated by `wot:sync-vehicles`; null until it next runs.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_vehicles', function (Blueprint $table) {
            $table->json('crew')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_vehicles', function (Blueprint $table) {
            $table->dropColumn('crew');
        });
    }
};
