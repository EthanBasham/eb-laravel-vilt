<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wot\BoardFiltersRequest;
use App\Http\Requests\Wot\SaveBattlePassCrewRequest;
use App\Http\Requests\Wot\UpdateCrewCountRequest;
use App\Http\Requests\Wot\UpdateTankCrewRequest;
use App\Models\WotAccount;
use App\Models\WotBattlePassCrew;
use App\Models\WotCrewBook;
use App\Models\WotCrewRecruit;
use App\Models\WotGrindSetting;
use App\Models\WotTankCrew;
use App\Models\WotVehicle;
use App\Services\Wargaming\CrewBoard;
use App\Services\Wargaming\CrewInventory;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Crews: everything about who is in the tanks rather than about the tanks.
 *
 * Four tabs over one page, the way Grinding holds five. None of what they show
 * comes from the API — Wargaming publishes crew roles and the skills attached
 * to them, and nothing whatever about a player's own tankmen — so every figure
 * here is entered by hand and every write below is a write of something typed.
 */
class CrewController extends Controller
{
    public function index(Request $request, CrewBoard $board, CrewInventory $inventory): Response
    {
        $account = $request->user()->wotAccount;

        if (! $account) {
            return Inertia::render('Connect');
        }

        $settings = WotGrindSetting::firstOrNew(['wot_account_id' => $account->id]);

        return Inertia::render('Crews', [
            'crews' => $board->for($account),
            ...$inventory->for($account),
            'battle_pass' => $this->battlePass($account),
            // Passed through as stored, null included — the client tells "never
            // saved" from "saved as empty" by it.
            'settings' => ['crews_filters' => $settings->crews_filters],
            'xp_progression' => $this->xpProgression(),
            // Static lists the editors build their selects from, shared rather
            // than restated in JavaScript so config stays the one description of
            // what a role, a status or a gender can be.
            'roles' => (array) config('wargaming.crew_roles'),
            'statuses' => (array) config('wargaming.crew_statuses'),
            'genders' => (array) config('wargaming.crew_genders'),
            /*
             * Every vehicle in the game, for the Battle Pass tank picker — a
             * tanker can be posted to a premium as readily as to a tech-tree
             * tank, so this is not the board's line list.
             *
             * Deferred because it is a thousand rows that only one of four tabs
             * needs: the page paints without it and Inertia fetches it straight
             * after.
             */
            'vehicles' => Inertia::defer(fn (): array => $this->vehicleOptions()),
        ]);
    }

    /**
     * Records a whole crew, as the editor sends it.
     *
     * The set arrives complete rather than field by field, so a seat missing
     * from the payload is one that has been emptied and its row goes. That is
     * also why this is a PUT: it replaces the crew rather than amending it.
     */
    public function updateCrew(UpdateTankCrewRequest $request, int $tankId): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        $vehicle = WotVehicle::where('tank_id', $tankId)->first();

        abort_unless($vehicle, 404);

        $members = collect($request->validated('members'))->keyBy('slot');

        /*
         * A seat the vehicle does not have cannot be crewed. Slots are
         * positions in the encyclopedia's own crew list, so anything past the
         * end of it is a client that has fallen out of step with a patch —
         * better to reject it than to store a member nothing will ever render.
         */
        abort_if($members->keys()->contains(fn (int $slot): bool => $slot >= count((array) $vehicle->crew)), 422);

        $crew = WotTankCrew::firstOrNew([
            'wot_account_id' => $account->id,
            'tank_id' => $tankId,
        ]);

        $crew->is_balanced = $request->boolean('is_balanced');
        $crew->save();

        $this->replaceMembers($crew, $members);

        return back(fallback: route('wot.crews'));
    }

    /**
     * Empties a vehicle.
     *
     * Deletes the row rather than zeroing it, because no crew is the absence of
     * a record and not a crew of zeroes — the board's red state is "nothing has
     * been said about this tank", which a row full of defaults would not be.
     */
    public function destroyCrew(Request $request, int $tankId): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        // Members go with it, on the cascade declared in the migration.
        WotTankCrew::where('wot_account_id', $account->id)
            ->where('tank_id', $tankId)
            ->delete();

        return back(fallback: route('wot.crews'));
    }

    /**
     * How many recruits of one kind are in the barracks.
     *
     * Which kind is in the route, so the payload is the bare number — see
     * UpdateCrewCountRequest.
     */
    public function updateRecruit(UpdateCrewCountRequest $request, string $recruitKey): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        // Validated against config rather than in the request, because the key
        // arrives in the path: an unknown kind is a URL that does not exist.
        abort_unless(array_key_exists($recruitKey, (array) config('wargaming.crew_recruits')), 404);

        WotCrewRecruit::updateOrCreate(
            ['wot_account_id' => $account->id, 'recruit_key' => $recruitKey],
            ['quantity' => $request->integer('quantity')],
        );

        return back(fallback: route('wot.crews'));
    }

    /**
     * How many books of one type, for one nation, are held.
     *
     * The two special items come through here too, under the nation
     * 'universal': they are counted the same way and the tab draws them in the
     * same column.
     */
    public function updateBook(UpdateCrewCountRequest $request, string $bookType, string $nation): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        abort_unless($this->isKnownBook($bookType, $nation), 404);

        WotCrewBook::updateOrCreate(
            ['wot_account_id' => $account->id, 'book_type' => $bookType, 'nation' => $nation],
            ['quantity' => $request->integer('quantity')],
        );

        return back(fallback: route('wot.crews'));
    }

    public function storeBattlePassCrew(SaveBattlePassCrewRequest $request): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        $account->battlePassCrew()->create($this->postedCrew($request));

        return back(fallback: route('wot.crews'));
    }

    /**
     * Edits one tanker.
     *
     * Fields arrive one at a time — the row saves as you leave each control —
     * so only what was sent is written.
     */
    public function updateBattlePassCrew(SaveBattlePassCrewRequest $request, WotBattlePassCrew $crew): RedirectResponse
    {
        $this->authoriseCrew($request, $crew);

        $crew->update($this->postedCrew($request, $crew));

        return back(fallback: route('wot.crews'));
    }

    public function destroyBattlePassCrew(Request $request, WotBattlePassCrew $crew): RedirectResponse
    {
        $this->authoriseCrew($request, $crew);

        $crew->delete();

        return back(fallback: route('wot.crews'));
    }

    /**
     * Remembers where the Crews board's filter row was left.
     *
     * 204 rather than the `back()` the rest of this controller returns, for the
     * reason the grinding board's does: the caller is a standalone `useHttp`
     * request and the board on screen already shows the filtered state.
     */
    public function updateFilters(BoardFiltersRequest $request): HttpResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        WotGrindSetting::mergeFilters(
            $account,
            (string) $request->string('board'),
            collect($request->validated())->except('board')->all(),
        );

        return response()->noContent();
    }

    /**
     * Writes the posted seats over the crew, and drops the ones left out.
     *
     * @param  Collection<int, array<string, mixed>>  $members
     */
    private function replaceMembers(WotTankCrew $crew, Collection $members): void
    {
        $crew->members()->whereNotIn('slot', $members->keys()->all())->delete();

        foreach ($members as $slot => $member) {
            $crew->members()->updateOrCreate(['slot' => $slot], $member);
        }
    }

    /**
     * What to write for a Battle Pass tanker, with the posting cleared when
     * they are not in a tank.
     *
     * A crew member in the barracks who still named a vehicle would show up as
     * that tank's crew anywhere this list is read by tank, so the two fields
     * follow the status rather than being left to the client to blank.
     *
     * @return array<string, mixed>
     */
    private function postedCrew(SaveBattlePassCrewRequest $request, ?WotBattlePassCrew $crew = null): array
    {
        $values = $request->validated();

        // The status as it will stand after this write — which is the posted
        // one on an edit that changes it, and the stored one on an edit that
        // does not mention it.
        $status = $values['status'] ?? $crew?->status ?? 'uncollected';

        if ($status !== 'in_tank') {
            return [...$values, 'tank_id' => null, 'crew_role' => null];
        }

        return $values;
    }

    private function authoriseCrew(Request $request, WotBattlePassCrew $crew): void
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);
        // 404 rather than 403: another account's roster is not something this
        // user should be able to confirm the existence of.
        abort_unless($crew->wot_account_id === $account->id, 404);
    }

    /**
     * Whether a book cell exists at all.
     *
     * The three classical books are held per nation or universally; the two
     * special items only ever universally, since neither is tied to a nation.
     */
    private function isKnownBook(string $bookType, string $nation): bool
    {
        if (array_key_exists($bookType, (array) config('wargaming.crew_book_specials'))) {
            return $nation === WotCrewBook::UNIVERSAL;
        }

        return array_key_exists($bookType, (array) config('wargaming.crew_books'))
            && ($nation === WotCrewBook::UNIVERSAL
                || array_key_exists($nation, (array) config('wargaming.nations')));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function battlePass(WotAccount $account): array
    {
        return $account->battlePassCrew()
            ->inDefaultOrder()
            ->get()
            ->map(fn (WotBattlePassCrew $crew): array => [
                'id' => $crew->id,
                'name' => $crew->name,
                'nation' => $crew->nation,
                'season' => $crew->season,
                'gender' => $crew->gender,
                'status' => $crew->status,
                'tank_id' => $crew->tank_id,
                'crew_role' => $crew->crew_role,
            ])
            ->all();
    }

    /**
     * The crew training progression, as rows for the table above the board.
     *
     * Nothing computes against these yet — they are recorded so that something
     * can later — so the page lists them and stops there.
     *
     * @return list<array{level: int, label: string, xp: int}>
     */
    private function xpProgression(): array
    {
        return collect((array) config('wargaming.crew_xp'))
            ->map(fn (int $xp, int $level): array => [
                'level' => $level,
                // Level 0 is the qualification itself rather than a skill on
                // top of it, and is named the way the game names it.
                'label' => $level === 0 ? 'Base — 100%' : "Skill level {$level}",
                'xp' => $xp,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vehicleOptions(): array
    {
        return WotVehicle::query()
            ->get(['tank_id', 'name', 'short_name', 'tier', 'nation', 'type'])
            ->map(fn (WotVehicle $vehicle): array => [
                'tank_id' => $vehicle->tank_id,
                // short_name, like every other vehicle list here.
                'name' => $vehicle->short_name ?? $vehicle->name,
                'tier' => $vehicle->tier,
                'nation' => $vehicle->nation,
                // The picker filters and badges by type, like the grinding
                // page's tank picker it is built after.
                'type' => $vehicle->type,
            ])
            ->sortBy(fn (array $option): array => [WotVehicle::rankOf($option['nation']), -$option['tier'], $option['name']])
            ->values()
            ->all();
    }
}
