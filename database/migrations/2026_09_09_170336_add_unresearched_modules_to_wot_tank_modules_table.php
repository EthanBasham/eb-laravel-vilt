<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules explicitly *not* researched.
 *
 * A second list rather than a flag, because researched is now a tri-state: said
 * yes, said no, or said nothing and inherit the default. It needed to become
 * one the moment the default stopped being "no" — with a default of yes, an
 * absent id would read as researched and there would be no way to say otherwise.
 *
 * The same shape the purchase board's is_purchased takes, where an explicit row
 * beats the inference drawn from play history.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_tank_modules', function (Blueprint $table) {
            $table->json('unresearched_module_ids')->nullable()->after('researched_module_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_tank_modules', function (Blueprint $table) {
            $table->dropColumn('unresearched_module_ids');
        });
    }
};
