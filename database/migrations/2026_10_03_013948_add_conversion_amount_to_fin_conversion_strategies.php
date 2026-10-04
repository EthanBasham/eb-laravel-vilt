<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a fixed-amount strategy converts each year, in today's dollars. Only
 * the `fixed` kind reads it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->decimal('conversion_amount', 15, 2)->nullable()->after('fill_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->dropColumn('conversion_amount');
        });
    }
};
