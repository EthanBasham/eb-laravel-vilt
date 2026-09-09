<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each module unlocks.
 *
 * The encyclopedia has published this all along, inside modules_tree, and the
 * sync was dropping it. Without it a module reads as independently researchable
 * when 206 of the 1,188 upgrade modules on the Free XP board — 17% — actually
 * sit behind another upgrade, so planning one of those understated its cost.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_vehicle_modules', function (Blueprint $table) {
            /*
             * Forward-pointing, exactly like wot_vehicles.next_tanks, because
             * that is the direction the API publishes. Prerequisites are the
             * inversion of it, worked out in PHP the same way TechTree inverts
             * the vehicle tree.
             *
             * Nullable: a terminal module unlocks nothing, and the API omits
             * the key entirely rather than sending an empty list.
             */
            $table->json('next_modules')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_vehicle_modules', function (Blueprint $table) {
            $table->dropColumn('next_modules');
        });
    }
};
