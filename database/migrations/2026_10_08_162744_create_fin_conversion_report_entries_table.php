<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Roth report as a list of its columns: each row is one strategy run on
 * one projection. Until now a strategy named its own projection and carried
 * `is_compared`, so the same strategy on three projections was three rows to
 * keep alike by hand.
 *
 * A strategy now holds settings alone, and may be in the report on any number
 * of projections. A null `scenario_id` is the income and expenses as entered.
 * Both ends cascade: a column goes with its strategy, and with its projection.
 *
 * Existing rows are carried over. Every compared strategy becomes a column
 * on the projection it named, and strategies of one user that differ in
 * nothing but their projection — the copies this change does away with —
 * are folded into the first of them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fin_conversion_report_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversion_strategy_id')->constrained('fin_conversion_strategies')->cascadeOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained('fin_scenarios')->cascadeOnDelete();
            $table->timestamps();
        });

        // The first strategy with each set of settings, by those settings.
        $kept = [];
        $columns = [];

        foreach (DB::table('fin_conversion_strategies')->orderBy('id')->get() as $strategy) {
            $settings = collect((array) $strategy)->except(['id', 'scenario_id', 'is_compared', 'created_at', 'updated_at'])->all();
            $keeper = $kept[json_encode($settings)] ??= $strategy->id;
            $column = $keeper.':'.($strategy->scenario_id ?? 0);

            if ($strategy->is_compared && ! isset($columns[$column])) {
                $columns[$column] = true;

                DB::table('fin_conversion_report_entries')->insert([
                    'user_id' => $strategy->user_id,
                    'conversion_strategy_id' => $keeper,
                    'scenario_id' => $strategy->scenario_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($keeper !== $strategy->id) {
                DB::table('fin_conversion_strategies')->where('id', $strategy->id)->delete();
            }
        }

        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scenario_id');
            $table->dropColumn('is_compared');
        });
    }

    /**
     * Reverse the migrations.
     *
     * A strategy goes back to the first projection it was reported on, and
     * to being compared if it was reported on any. The rest of its columns,
     * and the strategies folded together on the way up, do not come back.
     */
    public function down(): void
    {
        Schema::table('fin_conversion_strategies', function (Blueprint $table) {
            $table->foreignId('scenario_id')->nullable()->after('user_id')->constrained('fin_scenarios')->nullOnDelete();
            $table->boolean('is_compared')->default(true)->after('kind');
        });

        $first = DB::table('fin_conversion_report_entries')->orderBy('id')->get()->unique('conversion_strategy_id')->keyBy('conversion_strategy_id');

        foreach (DB::table('fin_conversion_strategies')->pluck('id') as $id) {
            DB::table('fin_conversion_strategies')->where('id', $id)->update([
                'scenario_id' => $first[$id]->scenario_id ?? null,
                'is_compared' => isset($first[$id]),
            ]);
        }

        Schema::dropIfExists('fin_conversion_report_entries');
    }
};
