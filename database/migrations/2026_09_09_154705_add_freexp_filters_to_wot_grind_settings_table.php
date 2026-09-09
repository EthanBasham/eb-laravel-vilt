<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the Free XP board's filter row was left.
 *
 * A second column beside purchase_filters rather than one board_filters blob
 * holding both. The two boards filter on different things — one hides lines you
 * own, the other shows only lines you have planned on — so they were never one
 * value, and splitting later would mean migrating live data for no gain.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            // Nullable and null-means-unset, exactly like purchase_filters.
            $table->json('freexp_filters')->nullable()->after('purchase_filters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->dropColumn('freexp_filters');
        });
    }
};
