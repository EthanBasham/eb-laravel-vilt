<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roth conversion strategies, for the Retirement Strategizer: each row is one
 * way of moving traditional money to Roth, with the assumptions it is run
 * under, kept so several can be compared side by side.
 *
 * Nearly every column is nullable, and null means "whatever follows from the
 * rest": the inflation rate falls back to the projection's and then the
 * profile's, the growth rate to the fleet's own, the ages to the usual window
 * for the kind of strategy, and a missing projection is the incomes and
 * expenses as entered. `fill_rate` null is "whichever bracket the year's
 * income is already in".
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fin_conversion_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The projection it builds on. Losing it falls back to the flows
            // as entered rather than taking the strategy with it.
            $table->foreignId('scenario_id')->nullable()->constrained('fin_scenarios')->nullOnDelete();

            $table->string('name');
            // A key of config `finance.conversion_strategies`: none, lump, even, …
            $table->string('kind', 20);
            $table->unsignedTinyInteger('convert_from_age')->nullable();
            $table->unsignedTinyInteger('convert_until_age')->nullable();
            $table->decimal('fill_rate', 5, 2)->nullable();

            $table->decimal('inflation_rate', 6, 3)->nullable();
            $table->decimal('growth_rate', 6, 3)->nullable();

            // Whoever inherits: a charity pays no tax on traditional money;
            // a person pays it on top of this income, over ten years.
            $table->boolean('heir_is_charity')->default(false);
            $table->decimal('heir_income', 15, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fin_conversion_strategies');
    }
};
