<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Retirement Strategizer's Monte Carlo settings, one row per user, and
 * the last results worked out in the background.
 *
 * A run small enough for a page load is never stored: it is worked out each
 * time the page is opened. Only a background run writes `results`, stamped
 * with `inputs_hash` — a hash of everything the run read — so the page can
 * tell results that still describe the strategies from results that do not.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fin_monte_carlo_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // How many markets each strategy is run through; 0 is off.
            $table->unsignedInteger('runs')->default(100);
            // Standard deviations, in points, of a year's return and a year's
            // inflation around the strategy's own rates.
            $table->decimal('return_volatility', 5, 2)->default(12);
            $table->decimal('inflation_volatility', 5, 2)->default(1);
            // Which set of random markets: the same seed draws the same ones.
            $table->unsignedInteger('seed')->default(1);

            // idle, queued, running, done or failed: the background run.
            $table->string('status', 20)->default('idle');
            $table->string('inputs_hash', 64)->nullable();
            $table->json('results')->nullable();
            $table->timestamp('ran_at')->nullable();
            $table->string('error')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fin_monte_carlo_runs');
    }
};
