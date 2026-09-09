<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the Tanks to Purchase filter row was left.
 *
 * Sits beside the planning figures rather than in a table of its own: it is the
 * same shape — one row per account, board-wide, belonging to no target — and
 * the settings row is already loaded on every render of the board.
 *
 * One JSON column rather than four typed ones. Nothing queries or aggregates
 * these; they are read whole, written whole, and handed straight to the client,
 * and a filter added to the tab later should not need a migration.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            // Nullable, and null is load-bearing: it means the filters have
            // never been saved, which is what lets a first visit fall back to
            // seeding hidden tiers from the ones the server reports as bought
            // out. An empty object is a deliberate "show everything".
            $table->json('purchase_filters')->nullable()->after('garage_slots_vacant');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->dropColumn('purchase_filters');
        });
    }
};
