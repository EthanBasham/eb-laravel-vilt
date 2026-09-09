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
        Schema::table('wot_vehicles', function (Blueprint $table) {
            // {tank_id: xp_cost} — what this vehicle unlocks and for how much.
            // Null on premium and gift vehicles, which sit outside the tech
            // tree: 507 of the 1,028 vehicles have no research line at all.
            $table->json('next_tanks')->nullable();

            // Modules researchable from this vehicle, each with its own
            // price_xp. Stored whole rather than normalised into a table:
            // nothing queries an individual module, the shape is Wargaming's to
            // change, and it is only a few kB per vehicle.
            $table->json('modules_tree')->nullable();

            $table->boolean('is_gift')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_vehicles', function (Blueprint $table) {
            $table->dropColumn(['next_tanks', 'modules_tree', 'is_gift']);
        });
    }
};
