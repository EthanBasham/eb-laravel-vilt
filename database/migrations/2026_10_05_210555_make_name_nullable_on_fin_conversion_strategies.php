<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A strategy's name becomes optional. One without is shown by its projection
 * and its kind, which is what most names only repeated.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // A name is required again, so the unnamed take their kind's.
        DB::table('fin_conversion_strategies')->whereNull('name')->update(['name' => DB::raw('kind')]);

        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
