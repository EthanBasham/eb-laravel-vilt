<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a vehicle's next fragments are meant to come from.
 *
 * Three counters rather than one because the sources are an OR chosen per
 * fragment: own-nation blueprints, another nation in the same group at six to
 * one, or universal ones — and a blueprint may mix them. A single "planned"
 * figure could not be costed, since the group rate is six times the national
 * one and nothing would say how many of the fragments took it.
 *
 * All three count fragments, like blueprint_fragments beside them, not the raw
 * blueprints they are crafted from. What that comes to in blueprints is the
 * tier's business and lives in config('wargaming.blueprint_costs').
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->unsignedSmallInteger('blueprint_plan_own')->default(0)->after('blueprint_fragments');
            $table->unsignedSmallInteger('blueprint_plan_group')->default(0)->after('blueprint_plan_own');
            $table->unsignedSmallInteger('blueprint_plan_universal')->default(0)->after('blueprint_plan_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->dropColumn(['blueprint_plan_own', 'blueprint_plan_group', 'blueprint_plan_universal']);
        });
    }
};
