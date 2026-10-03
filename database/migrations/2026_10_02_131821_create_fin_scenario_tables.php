<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projections & scenarios for the Financial Fleet sub-project: a named set of
 * assumptions about how each income and expense moves from here on.
 *
 * A scenario never copies a flow. It holds only what it changes about one —
 * a growth rate, and the years pinned to an amount of their own — so a flow
 * edited on the income & expenses page is edited in every scenario at once,
 * and a flow a scenario says nothing about is projected as it stands.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fin_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            $table->timestamps();
        });

        Schema::create('fin_scenario_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained('fin_scenarios')->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained('fin_flows')->cascadeOnDelete();

            // Null leaves the flow growing at its own rate.
            $table->decimal('annual_growth_rate', 6, 3)->nullable();
            // { "2031": 84000 } — the years given an amount of their own,
            // whatever the rate would have made them.
            $table->json('overrides')->nullable();

            $table->timestamps();

            $table->unique(['scenario_id', 'flow_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fin_scenario_flows');
        Schema::dropIfExists('fin_scenarios');
    }
};
