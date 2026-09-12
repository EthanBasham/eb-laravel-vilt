<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the Crews board's filter row was left.
 *
 * The fifth column of its kind on this table, which is named for the grinding
 * page only because that is where boards started. It holds filter state for
 * every tech-tree board in the app, and the Crews board is one — a table of its
 * own would have bought nothing but a second lookup on a page that already
 * loads this one.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->json('crews_filters')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->dropColumn('crews_filters');
        });
    }
};
