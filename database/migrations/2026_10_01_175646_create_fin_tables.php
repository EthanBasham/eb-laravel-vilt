<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every table behind the Financial Fleet sub-project (/finance), in one
 * migration on purpose: the sub-project is a first-pass prototype, and one file
 * means one `migrate:rollback` takes all of it back out.
 *
 * Nothing here touches `users` — each table carries its own `user_id` — so
 * removing the sub-project leaves no column behind on a shared table.
 *
 * Money is decimal(15, 2). Rates are whole percentages (4.25 means 4.25%), to
 * three places so a 0.035% expense ratio survives.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The handful of facts about a person the tools need: enough to place
        // them on a tax table and on a retirement timeline.
        Schema::create('fin_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->date('birth_date')->nullable();
            $table->string('filing_status')->default('single');
            $table->unsignedTinyInteger('retirement_age')->default(65);
            $table->unsignedTinyInteger('life_expectancy')->default(92);
            $table->decimal('inflation_rate', 6, 3)->default(2.5);

            $table->timestamps();
        });

        /*
         * One row of the fleet: an asset or a liability. Both sides share a
         * table because they share nearly every column, and because a net
         * worth is one query over it rather than two.
         *
         * `annual_rate` is growth for an asset and APR for a liability.
         * `monthly_contribution` is what goes in each month for an asset and
         * the payment for a liability.
         */
        Schema::create('fin_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('side');
            $table->string('type');
            $table->string('name');
            $table->string('institution')->nullable();

            $table->decimal('balance', 15, 2)->default(0);
            $table->decimal('annual_rate', 7, 3)->default(0);
            $table->decimal('monthly_contribution', 15, 2)->default(0);

            // A liability secured against an asset — the mortgage on a house —
            // so equity can be reported for the pair.
            $table->foreignId('secured_by_id')->nullable()->constrained('fin_holdings')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'side']);
        });

        // What an account is made of. Optional: an account with no positions
        // is worth its own `balance`; one with positions is worth their sum.
        Schema::create('fin_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_id')->constrained('fin_holdings')->cascadeOnDelete();

            $table->string('name');
            $table->string('symbol')->nullable();
            $table->string('asset_class');
            $table->decimal('value', 15, 2)->default(0);
            $table->decimal('expected_return', 7, 3)->default(0);
            $table->decimal('expense_ratio', 6, 3)->default(0);

            $table->timestamps();
        });

        /*
         * Money moving on a schedule — an income stream or an expense. It
         * stands alone (a salary, a subscription) or hangs off a holding (the
         * rent on a property, its insurance), and goes when the holding does.
         */
        Schema::create('fin_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('holding_id')->nullable()->constrained('fin_holdings')->cascadeOnDelete();

            $table->string('direction');
            $table->string('category');
            $table->string('name');

            $table->decimal('amount', 15, 2);
            $table->string('frequency')->default('monthly');
            // Only read for an hourly flow, where `amount` is the rate.
            $table->decimal('hours_per_week', 5, 2)->nullable();
            $table->decimal('annual_growth_rate', 6, 3)->default(0);

            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_essential')->default(false);

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'direction']);
        });

        Schema::create('fin_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->decimal('target_amount', 15, 2);
            $table->decimal('saved_amount', 15, 2)->default(0);
            $table->date('target_date');

            $table->timestamps();
        });

        /*
         * The fleet's totals on a given day, and what it was projected to do
         * from there. `projection` is the month-by-month net worth as the
         * projector saw it on the day — stored rather than recomputed, because
         * "what I expected then" stops being recoverable the moment a rate or
         * a balance is edited.
         */
        Schema::create('fin_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('taken_on');
            $table->decimal('assets', 15, 2);
            $table->decimal('liabilities', 15, 2);
            $table->decimal('net_worth', 15, 2);
            $table->json('projection');
            $table->string('note')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'taken_on']);
        });

        // What a flow actually came to in a month, against what it was
        // budgeted at. `month` is always the first of the month.
        Schema::create('fin_actuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('fin_flows')->cascadeOnDelete();

            $table->date('month');
            $table->decimal('amount', 15, 2);

            $table->timestamps();

            $table->unique(['flow_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fin_actuals');
        Schema::dropIfExists('fin_snapshots');
        Schema::dropIfExists('fin_goals');
        Schema::dropIfExists('fin_flows');
        Schema::dropIfExists('fin_positions');
        Schema::dropIfExists('fin_holdings');
        Schema::dropIfExists('fin_profiles');
    }
};
