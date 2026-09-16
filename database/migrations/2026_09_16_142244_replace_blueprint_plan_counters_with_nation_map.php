<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

/**
 * One planned-fragment count per nation, in place of the own/group/universal
 * counters added the day before.
 *
 * Those three were built on a rule that turned out to be wrong: that a fragment
 * is paid for out of one source or another. It is not — every fragment costs
 * national blueprints *and* universal ones together, and the only choice is
 * which nation pays the national half: the vehicle's own, or a peer in its
 * group at six to one. There is no such thing as a fragment bought with
 * universal blueprints alone, so 'blueprint_plan_universal' was recording
 * something that cannot happen, and 'blueprint_plan_group' could not say which
 * peer would pay.
 *
 * A JSON map keyed by nation slug — {"usa": 3, "uk": 1} — rather than a column
 * per nation. A vehicle can only ever draw on its own group, which is two to
 * four nations, and which nations those are is config the database has no
 * business mirroring. Counts are fragments, like blueprint_fragments beside it;
 * what they cost in raw blueprints is the tier's business and lives in
 * config('wargaming.blueprint_costs').
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->json('blueprint_plan')->nullable()->after('blueprint_fragments');
        });

        $this->carryOwnNationPlans();

        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->dropColumn(['blueprint_plan_own', 'blueprint_plan_group', 'blueprint_plan_universal']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->unsignedSmallInteger('blueprint_plan_own')->default(0)->after('blueprint_fragments');
            $table->unsignedSmallInteger('blueprint_plan_group')->default(0)->after('blueprint_plan_own');
            $table->unsignedSmallInteger('blueprint_plan_universal')->default(0)->after('blueprint_plan_group');
        });

        /*
         * Everything planned against the vehicle's own nation goes back where
         * it came from. A plan against a peer nation has no column to return
         * to — 'group' would keep the count but lose which nation it named —
         * so it is summed in there as the nearest true thing.
         */
        foreach ($this->plans() as $row) {
            $plan = (array) json_decode((string) $row->blueprint_plan, associative: true);
            $own = (int) ($plan[$row->nation] ?? 0);

            DB::table('wot_tank_purchases')->where('id', $row->id)->update([
                'blueprint_plan_own' => $own,
                'blueprint_plan_group' => array_sum($plan) - $own,
            ]);
        }

        Schema::table('wot_tank_purchases', function (Blueprint $table) {
            $table->dropColumn('blueprint_plan');
        });
    }

    /**
     * Move the own-nation counter into the map, and drop the other two.
     *
     * blueprint_plan_group named no nation, and blueprint_plan_universal named
     * a source that cannot pay on its own; neither can be turned into an entry
     * here without inventing the missing half. They are let go rather than
     * guessed at — the counters are a day old, and a plan is re-entered in a
     * few clicks.
     */
    private function carryOwnNationPlans(): void
    {
        foreach ($this->plans() as $row) {
            if ((int) $row->blueprint_plan_own === 0) {
                continue;
            }

            DB::table('wot_tank_purchases')
                ->where('id', $row->id)
                ->update(['blueprint_plan' => json_encode([$row->nation => (int) $row->blueprint_plan_own])]);
        }
    }

    /**
     * Every purchase row with the nation of the vehicle it is about.
     *
     * Joined rather than looked up per row: the plan is keyed by nation and the
     * purchase table does not carry one.
     *
     * @return Collection<int, object>
     */
    private function plans(): Collection
    {
        return DB::table('wot_tank_purchases')
            ->join('wot_vehicles', 'wot_vehicles.tank_id', '=', 'wot_tank_purchases.tank_id')
            ->select('wot_tank_purchases.*', 'wot_vehicles.nation')
            ->get();
    }
};
