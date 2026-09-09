<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where the Blueprints board's filter row was left. The fourth and last. */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->json('blueprints_filters')->nullable()->after('xp_filters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_grind_settings', function (Blueprint $table) {
            $table->dropColumn('blueprints_filters');
        });
    }
};
