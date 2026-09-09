<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the XP Remaining board's filter row was left.
 *
 * The third of three, one column per board. They stay separate for the reason
 * the second one did: the boards filter on different things, so they were never
 * one value.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->json('xp_filters')->nullable()->after('freexp_filters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->dropColumn('xp_filters');
        });
    }
};
