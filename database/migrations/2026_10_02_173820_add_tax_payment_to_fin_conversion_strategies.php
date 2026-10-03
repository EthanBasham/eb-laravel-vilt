<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the tax on a conversion is paid from: savings outside it, the
 * converted money itself, or some of each.
 *
 * `tax_payment` is a key of config `finance.conversion_tax_payments`.
 * `tax_outside_amount` is only read by the two split modes, and says how much
 * comes from outside — a percentage of the conversion's tax for `percent`,
 * dollars a year (today's) for `flat` — with the rest taken out of the
 * conversion before it reaches the Roth.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->string('tax_payment', 20)->default('outside')->after('fill_rate');
            $table->decimal('tax_outside_amount', 15, 2)->nullable()->after('tax_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->dropColumn(['tax_payment', 'tax_outside_amount']);
        });
    }
};
