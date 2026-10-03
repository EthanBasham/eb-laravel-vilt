<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of an income is taxed at all, as a percentage, beside how it is
 * taxed (`taxation`). 100 unless someone says otherwise — the case in mind is
 * Social Security, of which at most 85% is ever taxable.
 *
 * It takes over from a fixed 85% that config applied to every Social Security
 * income. Existing rows are left at the default of 100, deliberately: the
 * share depends on the household's other income, so it is theirs to set.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_flows', function (Blueprint $table) {
            $table->decimal('taxed_portion', 6, 3)->default(100)->after('taxation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_flows', function (Blueprint $table) {
            $table->dropColumn('taxed_portion');
        });
    }
};
