<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How each income is taxed, and the three figures that tax needs which the
 * profile did not carry.
 *
 * `fin_flows.taxation` replaces the `is_taxable` flag: null is what false
 * was — not taxed — and otherwise it names one of four treatments
 * (config `finance.flow_taxations`). Existing taxable incomes are given the
 * treatment their category suggests, which is the same default the form
 * offers for a new one.
 *
 * On the profile: `standard_deduction` and `ltcg_brackets` are null until
 * someone sets their own, and null means the built-in figure for the filing
 * status — so changing filing status keeps moving them until they are pinned.
 * `ltcg_brackets` has the shape of the state brackets, `{rate, up_to}`.
 * `se_tax_rate` is the whole self-employment rate; W-2 wages pay half of it.
 */
return new class extends Migration
{
    /** The treatment each income category starts on. Frozen here: a migration should not change with config. */
    private const DEFAULTS = [
        'salary' => 'w2',
        'contract' => 'self_employed',
        'business' => 'income_only',
        'rental' => 'income_only',
        'investment' => 'income_only',
        'pension' => 'income_only',
        'social_security' => 'income_only',
        'other_income' => 'income_only',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_flows', function (Blueprint $table) {
            $table->string('taxation', 20)->nullable()->after('annual_growth_rate');
        });

        foreach (self::DEFAULTS as $category => $taxation) {
            DB::table('fin_flows')->where('direction', 'income')->where('category', $category)->where('is_taxable', true)->update(['taxation' => $taxation]);
        }

        Schema::table('fin_flows', function (Blueprint $table) {
            $table->dropColumn('is_taxable');
        });

        Schema::table('fin_profiles', function (Blueprint $table) {
            $table->decimal('standard_deduction', 15, 2)->nullable();
            $table->decimal('se_tax_rate', 6, 3)->default(15.3);
            $table->json('ltcg_brackets')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_profiles', function (Blueprint $table) {
            $table->dropColumn(['standard_deduction', 'se_tax_rate', 'ltcg_brackets']);
        });

        Schema::table('fin_flows', function (Blueprint $table) {
            $table->boolean('is_taxable')->default(true);
        });

        DB::table('fin_flows')->whereNull('taxation')->update(['is_taxable' => false]);

        Schema::table('fin_flows', function (Blueprint $table) {
            $table->dropColumn('taxation');
        });
    }
};
