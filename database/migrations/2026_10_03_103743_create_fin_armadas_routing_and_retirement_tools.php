<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Armadas, itemised household expenses, where money lands and leaves, automated
 * transfers, a scenario's say over holdings, and the two retirement tools
 * beside the Roth conversions. One migration because they were built as one
 * piece of work; nothing here rewrites an existing row.
 *
 * - An **armada** is a named group of holdings and flows — "Real estate",
 *   "Foundational". `armada_id` is nullable on both: unassigned is a real
 *   answer. A flow hung off a holding, an item inside a household expense and
 *   an account inside another all follow their owner's armada rather than
 *   carrying one, so only the top-level row's is read (see the models'
 *   `armada_key`).
 * - `fin_flows.parent_id` makes a flow compound, as `fin_holdings.parent_id`
 *   does a holding: a "Household expenses" flow with items inside comes to
 *   their sum, and its own amount is the estimate used while it has none. One
 *   level deep; the form request enforces it.
 * - `fin_flows.account_id` is the asset an income is paid into or an expense
 *   is paid from. Distinct from `holding_id`, which is what the flow belongs
 *   to: the rent on a duplex belongs to the duplex and lands in checking.
 * - A **transfer** moves money between two holdings every month, in
 *   `sort_order`. See config `finance.transfer_kinds` for what each kind moves.
 * - `fin_scenario_holdings` is to a holding what `fin_scenario_flows` is to a
 *   flow: the growth rate and monthly contribution a scenario gives it, and
 *   the year-end values pinned by hand.
 * - The profile gains what Social Security needs to know about a household:
 *   each person's monthly benefit at full retirement age, and the spouse's
 *   birth date and life expectancy.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fin_armadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            $table->timestamps();
        });

        Schema::table('fin_holdings', function (Blueprint $table) {
            // Losing its armada leaves a holding unassigned, not deleted.
            $table->foreignId('armada_id')->nullable()->after('user_id')->constrained('fin_armadas')->nullOnDelete();
        });

        Schema::table('fin_flows', function (Blueprint $table) {
            $table->foreignId('armada_id')->nullable()->after('user_id')->constrained('fin_armadas')->nullOnDelete();
            // Cascade: an item inside a deleted expense has nowhere to be.
            $table->foreignId('parent_id')->nullable()->after('holding_id')->constrained('fin_flows')->cascadeOnDelete();
            // Losing the account leaves the flow unrouted, as it was before.
            $table->foreignId('account_id')->nullable()->after('parent_id')->constrained('fin_holdings')->nullOnDelete();
        });

        Schema::create('fin_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // A transfer is nothing without both ends.
            $table->foreignId('from_holding_id')->constrained('fin_holdings')->cascadeOnDelete();
            $table->foreignId('to_holding_id')->constrained('fin_holdings')->cascadeOnDelete();

            $table->string('name');
            // A key of config `finance.transfer_kinds`: sweep, fixed, top_up.
            $table->string('kind', 20);
            // A month's amount for `fixed`; the most a `sweep` or `top_up`
            // moves in a month, null for no limit.
            $table->decimal('amount', 15, 2)->nullable();
            // What a sweep leaves behind in the source, or a top-up keeps in
            // the destination.
            $table->decimal('keep_balance', 15, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['user_id', 'sort_order']);
        });

        Schema::create('fin_scenario_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained('fin_scenarios')->cascadeOnDelete();
            $table->foreignId('holding_id')->constrained('fin_holdings')->cascadeOnDelete();

            // Null on either leaves the holding its own.
            $table->decimal('annual_rate', 7, 3)->nullable();
            $table->decimal('monthly_contribution', 15, 2)->nullable();
            // { "2031": 410000 } — the year-ends given a value of their own.
            // Unlike a flow's pin, the years after carry on from it.
            $table->json('overrides')->nullable();

            $table->timestamps();

            $table->unique(['scenario_id', 'holding_id']);
        });

        Schema::table('fin_profiles', function (Blueprint $table) {
            // The monthly benefit at full retirement age, in today's dollars,
            // as the Social Security statement gives it.
            $table->decimal('ss_monthly_benefit', 15, 2)->nullable();
            $table->date('spouse_birth_date')->nullable();
            $table->decimal('spouse_ss_monthly_benefit', 15, 2)->nullable();
            // Null plans the spouse to the same age as the profile's owner.
            $table->unsignedTinyInteger('spouse_life_expectancy')->nullable();
        });

        Schema::create('fin_social_security_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->unsignedTinyInteger('claim_age');
            $table->unsignedTinyInteger('claim_months')->default(0);
            // Null when there is no spouse, or to claim at the same age.
            $table->unsignedTinyInteger('spouse_claim_age')->nullable();
            $table->unsignedTinyInteger('spouse_claim_months')->default(0);
            // Null follows the profile's inflation rate.
            $table->decimal('cola_rate', 6, 3)->nullable();
            // What a benefit taken early could earn, for the present value.
            // Null is the fleet's own blended rate.
            $table->decimal('discount_rate', 6, 3)->nullable();

            $table->timestamps();
        });

        Schema::create('fin_withdrawal_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained('fin_scenarios')->nullOnDelete();

            $table->string('name');
            // A key of config `finance.withdrawal_strategies`.
            $table->string('kind', 30);
            // The bracket a bracket-filling order draws traditional money up to.
            $table->decimal('fill_rate', 5, 2)->nullable();
            // A key of config `finance.spending_rules`, and whichever figure it reads.
            $table->string('spending_rule', 20)->default('projection');
            $table->decimal('spending_amount', 15, 2)->nullable();
            $table->decimal('spending_percent', 6, 3)->nullable();

            $table->decimal('inflation_rate', 6, 3)->nullable();
            $table->decimal('growth_rate', 6, 3)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fin_withdrawal_strategies');
        Schema::dropIfExists('fin_social_security_strategies');

        Schema::table('fin_profiles', function (Blueprint $table) {
            $table->dropColumn(['ss_monthly_benefit', 'spouse_birth_date', 'spouse_ss_monthly_benefit', 'spouse_life_expectancy']);
        });

        Schema::dropIfExists('fin_scenario_holdings');
        Schema::dropIfExists('fin_transfers');

        Schema::table('fin_flows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('armada_id');
        });

        Schema::table('fin_holdings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('armada_id');
        });

        Schema::dropIfExists('fin_armadas');
    }
};
