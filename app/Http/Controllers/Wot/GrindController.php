<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wot\StoreGrindRequest;
use App\Models\WotGrind;
use App\Models\WotVehicle;
use App\Services\Wargaming\AccountDashboard;
use App\Services\Wargaming\GrindTracker;
use App\Services\Wargaming\WargamingException;
use Inertia\Inertia;
use Inertia\Response;

class GrindController extends Controller
{
    public function index(Request $request, AccountDashboard $dashboard, GrindTracker $tracker): Response
    {
        $account = $request->user()->wotAccount;

        if (! $account) {
            return Inertia::render('Connect');
        }

        try {
            $stats = $dashboard->vehicleStatsFor($account);
        } catch (WargamingException $e) {
            if ($e->isInvalidAccessToken()) {
                $account->forgetToken();
            }

            return Inertia::render('Grinds', [
                'grinds' => [], 'options' => [], 'error' => $e->getMessage(),
            ]);
        }

        return Inertia::render('Grinds', [
            'grinds' => $tracker->for($account, $stats),
            'options' => $tracker->availableTargets($stats),
            'error' => null,
        ]);
    }
    public function store(StoreGrindRequest $request, AccountDashboard $dashboard, GrindTracker $tracker): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        $vehicle = WotVehicle::findOrFail($request->integer('tank_id'));
        $target = $this->resolveTarget($vehicle, $request->string('target_type')->toString(), $request->integer('target_id'));

        if (! $target) {
            return back()->with('error', 'That is not a valid research target for this vehicle.');
        }

        try {
            $stats = $dashboard->vehicleStatsFor($account);
        } catch (WargamingException $e) {
            return back()->with('error', $e->getMessage());
        }

        // The baseline is the vehicle's lifetime XP right now. Everything the
        // grind reports is measured forward from this point, because the API
        // exposes no per-vehicle unspent XP to start from instead.
        $baseline = (int) ($stats[$vehicle->tank_id]['xp'] ?? 0);

        WotGrind::updateOrCreate(
            [
                'wot_account_id' => $account->id,
                'tank_id' => $vehicle->tank_id,
                'target_type' => $target['type'],
                'target_id' => $target['id'],
            ],
            [
                'target_name' => $target['name'],
                'target_xp' => $target['xp'],
                'baseline_xp' => $baseline,
                'started_at' => now(),
                // Restarting a finished grind clears its completion rather than
                // leaving a row that reads as both restarted and done.
                'completed_at' => null,
            ],
        );

        return back()->with('success', "Tracking {$vehicle->name} → {$target['name']}.");
    }
    public function destroy(Request $request, WotGrind $grind): RedirectResponse
    {
        // Grinds are bound by primary key, so ownership has to be checked here
        // or any signed-in user could delete another's.
        abort_unless($grind->wot_account_id === $request->user()->wotAccount?->id, 404);

        $grind->delete();

        return back()->with('success', 'Grind removed.');
    }

    /**
     * @return array{type: string, id: int, name: string, xp: int}|null
     */
    private function resolveTarget(WotVehicle $vehicle, string $type, int $targetId): ?array
    {
        if ($type === WotGrind::TARGET_TANK) {
            $cost = ($vehicle->next_tanks ?? [])[$targetId] ?? null;

            if ($cost === null) {
                return null;
            }

            return [
                'type' => $type,
                'id' => $targetId,
                'name' => WotVehicle::find($targetId)?->name ?? "Tank {$targetId}",
                'xp' => (int) $cost,
            ];
        }

        foreach ($vehicle->modules_tree ?? [] as $module) {
            if ((int) $module['module_id'] === $targetId && ! ($module['is_default'] ?? false)) {
                return [
                    'type' => $type,
                    'id' => $targetId,
                    'name' => $module['name'],
                    'xp' => (int) $module['price_xp'],
                ];
            }
        }

        return null;
    }
}
