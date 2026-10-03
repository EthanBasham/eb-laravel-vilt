<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retirement accounts become one holding type with two facts recorded about
 * each, and any holding can now sit inside another.
 *
 * Until now "traditional" and "roth" were themselves the types, which left
 * nowhere to say whether an account is an IRA or a 401(k) — and the rules
 * differ by plan (contribution limits, employer money, RMD exemptions), so
 * the tools will want to know. `type` is now `retirement`; `plan_type` says
 * which plan and `tax_type` says traditional or Roth.
 *
 * `parent_id` is what makes a compound account: a retirement account that
 * holds several brokerage accounts is a parent row with a child row for each.
 * A parent with children is worth their sum, and a child is taxed as its
 * parent is. One level deep only — the form request enforces that, since a
 * foreign key cannot.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_holdings', function (Blueprint $table) {
            // Both null on anything that is not a retirement account.
            $table->string('plan_type')->nullable()->after('type');
            $table->string('tax_type')->nullable()->after('plan_type');

            // Cascade: an account inside a deleted account has nowhere to be.
            $table->foreignId('parent_id')->nullable()->after('user_id')->constrained('fin_holdings')->cascadeOnDelete();
        });

        /*
         * The old types only ever said traditional or Roth, so the plan has to
         * be guessed. A name that mentions the plan number settles it; anything
         * else becomes an IRA, the plan anyone can open, and can be corrected
         * on the holding's edit form.
         */
        foreach (['traditional', 'roth'] as $taxType) {
            foreach (['401' => '401k', '403' => '403b', '457' => '457b'] as $needle => $plan) {
                DB::table('fin_holdings')->where('type', $taxType)->where('name', 'like', "%{$needle}%")
                    ->update(['type' => 'retirement', 'plan_type' => $plan, 'tax_type' => $taxType]);
            }

            DB::table('fin_holdings')->where('type', $taxType)
                ->update(['type' => 'retirement', 'plan_type' => 'ira', 'tax_type' => $taxType]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Children are lifted out to stand on their own rather than deleted with
     * the column: rolling back should lose the nesting, not the balances.
     */
    public function down(): void
    {
        foreach (['traditional', 'roth'] as $taxType) {
            DB::table('fin_holdings')->where('type', 'retirement')->where('tax_type', $taxType)->update(['type' => $taxType]);
        }

        Schema::table('fin_holdings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['plan_type', 'tax_type']);
        });
    }
};
