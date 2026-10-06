<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pinned years a flow's rate starts again from: [2031]. A year pinned
 * and nothing more moves only itself; one listed here is also where the
 * years after it compound from, until the next one listed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_scenario_flows', function (Blueprint $table) {
            $table->json('restarts')->nullable()->after('overrides');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_scenario_flows', function (Blueprint $table) {
            $table->dropColumn('restarts');
        });
    }
};
