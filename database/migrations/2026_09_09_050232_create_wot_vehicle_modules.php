<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Researchable modules per vehicle, and which of them a grind step has done.
 *
 * Verified against the spreadsheet before building: the sheet's "XP to Max"
 * figure is exactly the sum of a vehicle's unresearched upgrade costs, matching
 * on 19 of 20 steps. The twentieth differed by 61,000 — precisely the Object
 * 705's 130 mm S-70 gun, already researched. So module XP stops being a number
 * to maintain by hand and becomes a consequence of which modules are ticked.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Normalised rather than a modules_tree JSON blob on wot_vehicles: the
        // grind board reads modules for one vehicle at a time and needs to sum
        // and filter them, which a table does and a blob does not.
        Schema::create('wot_vehicle_modules', function (Blueprint $table) {
            $table->id();

            /*
             * A module id is NOT unique on its own: the same engine or radio
             * fits many vehicles, so the encyclopedia repeats it under each.
             * Keying on module_id alone made a batch upsert fail with
             * "ON CONFLICT DO UPDATE command cannot affect row a second time".
             * The real identity is the pair.
             */
            $table->unsignedBigInteger('module_id');
            $table->unsignedBigInteger('tank_id');
            $table->string('name');
            // vehicleGun, vehicleTurret, vehicleEngine, vehicleChassis, vehicleRadio.
            $table->string('type', 32);
            $table->unsignedInteger('price_xp')->default(0);
            $table->unsignedBigInteger('price_credit')->default(0);

            // Stock equipment, fitted from the start — nothing to research.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique(['tank_id', 'module_id']);

            // Every read is "the upgrade modules for this vehicle".
            $table->index(['tank_id', 'is_default']);
        });

        Schema::table('wot_grind_steps', function (Blueprint $table) {
            // Module ids already researched on this step's vehicle.
            //
            // JSON rather than a pivot table: it is a small set, read and
            // written whole, and never queried across steps.
            $table->json('researched_modules')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_grind_steps', function (Blueprint $table) {
            $table->dropColumn('researched_modules');
        });

        Schema::dropIfExists('wot_vehicle_modules');
    }
};
