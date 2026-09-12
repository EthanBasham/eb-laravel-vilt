<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotTankCrew;
use App\Models\WotVehicle;

/**
 * The Crews view: who is sitting in each vehicle of the tech tree.
 *
 * Laid out like XP Remaining and Tanks to Purchase — the whole tree, one row
 * per line, one column per tier — but a cell here answers a question the API
 * cannot: Wargaming publishes crew *roles* and the skills attached to them, and
 * nothing at all about a given player's tankmen. So the composition of a cell
 * comes from the encyclopedia and everything written over it comes from the
 * player.
 *
 * A cell is a short string of letters, one per crew member, in the vehicle's
 * own slot order. Everything else it has to say is carried by how those letters
 * are drawn, which is the client's business; this class supplies the facts they
 * are drawn from.
 *
 * Premium vehicles are absent for the same reason they are absent from every
 * other board here: rows are research lines, and a premium sits outside the
 * tree. Their crews are real, but they are not on this grid.
 */
class CrewBoard
{
    public function __construct(private readonly TechTreeLines $lines) {}

    /**
     * The whole tech tree as one row per line.
     *
     * @return array<string, mixed>
     */
    public function for(WotAccount $account): array
    {
        $rows = $this->claimShared($this->lineRows($account));

        return [
            'rows' => $rows->all(),
            'tiers' => $this->tierColumns($rows),
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function lineRows(WotAccount $account): Collection
    {
        $lines = $this->lines->lines((int) config('wargaming.line_min_tier'));

        /*
         * Every crew the account has recorded, keyed by tank. Loaded whole
         * rather than per line: a vehicle appears on every line that runs
         * through it, and the alternative is the same row fetched once per
         * appearance.
         */
        $crews = WotTankCrew::where('wot_account_id', $account->id)
            ->with('members')
            ->get()
            ->keyBy('tank_id');

        return $lines->map(function (array $line) use ($crews): array {
            $cells = $line['vehicles']
                ->map(fn (WotVehicle $vehicle): array => $this->cell($vehicle, $crews->get($vehicle->tank_id)))
                ->sortBy('tier')
                ->values();

            return [
                ...collect($line)->except('vehicles')->all(),
                'cells' => $cells->keyBy('tier')->all(),
                // crews and banked_xp are not set here: they depend on which
                // cells this row owns, which claimShared() decides.
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(WotVehicle $vehicle, ?WotTankCrew $crew): array
    {
        $members = $this->members($vehicle, $crew);

        return [
            'tank_id' => $vehicle->tank_id,
            'name' => $vehicle->short_name ?? $vehicle->name,
            'tier' => $vehicle->tier,
            'members' => $members,
            /*
             * Whether anything has been recorded at all, which is a different
             * question from whether the vehicle has slots. No crew is the red
             * state, and it is the absence of the row that says so — which is
             * why the editor deletes rather than writing zeroes.
             */
            'has_crew' => $crew !== null,
            'is_balanced' => (bool) $crew?->is_balanced,
            'is_max' => (bool) $crew?->is_max,
            // 'none' | 'plain' | 'mixed' | 'all' — the four colours a cell can
            // take, decided on the model where the members are.
            'zero_state' => $crew?->zero_state ?? 'none',
            'banked_xp' => (int) $crew?->banked_xp,
            /*
             * Filled in by claimShared(). Seeded so every cell has the same
             * shape whether it ends up shared or not — the client reads these
             * on every cell, and a missing key would read as undefined.
             */
            'is_shared' => false,
            'shared_with' => null,
        ];
    }

    /**
     * One entry per seat in the vehicle, decorated with what has been recorded
     * against it.
     *
     * The seats come from the encyclopedia and are keyed by position, because
     * `member_id` repeats within a vehicle — an IS-7 carries two loaders, and a
     * role could not tell them apart.
     *
     * They are then ordered by role rather than left in the encyclopedia's own
     * order, which is not consistent between vehicles: an AT-1 lists its driver
     * before its gunner, most tanks the other way about. A column of cells that
     * all spell C G D R L is one a discrepancy jumps out of — a missing radio
     * operator, or a second loader — where a column that reorders itself per
     * vehicle has to be read tank by tank.
     *
     * `slot` is untouched by the sort. It is the encyclopedia's position and
     * what every stored member is keyed by, so reordering what is shown never
     * moves what is written.
     *
     * A vehicle synced before the crew column existed has no seats at all, and
     * renders as a cell with nothing in it rather than as an error.
     *
     * @return list<array<string, mixed>>
     */
    private function members(WotVehicle $vehicle, ?WotTankCrew $crew): array
    {
        $roles = (array) config('wargaming.crew_roles');
        $recorded = $crew?->members->keyBy('slot');

        return collect($vehicle->crew ?? [])
            ->values()
            ->map(function (array $seat, int $slot) use ($roles, $recorded): array {
                $role = $seat['member_id'] ?? null;
                $member = $recorded?->get($slot);

                return [
                    'slot' => $slot,
                    'role' => $role,
                    'name' => $roles[$role]['name'] ?? ucfirst((string) $role),
                    // The whole of a crew member on the board.
                    'letter' => $roles[$role]['letter'] ?? strtoupper(substr((string) $role, 0, 1)),
                    /*
                     * The other roles this one body covers — the IS-7's fourth
                     * seat is a Loader who is also the Radio Operator. The
                     * board spells one letter per body, so these are what the
                     * tooltip adds rather than what the cell shows.
                     */
                    'also' => $this->alsoRoles($seat, $role),
                    'zero_skills' => (int) ($member?->zero_skills ?? 0),
                    'skill_level' => (int) ($member?->skill_level ?? 0),
                    'is_max' => (bool) ($member?->is_max ?? false),
                    'banked_xp' => (int) ($member?->banked_xp ?? 0),
                ];
            })
            // Slot breaks the tie, so a vehicle's two loaders keep the order
            // the encyclopedia gave them rather than an arbitrary one.
            ->sortBy(fn (array $member): array => [$this->roleRank($member['role']), $member['slot']])
            ->values()
            ->all();
    }

    /**
     * Where a role sits in the order every cell spells its letters in.
     *
     * Anything unrecognised sorts to the end rather than to the front, so a
     * role added by a future patch appears after the five known ones instead of
     * silently displacing the commander — the same rule vehicle nations follow.
     */
    private function roleRank(?string $role): int
    {
        $order = array_flip(array_keys((array) config('wargaming.crew_roles')));

        return $order[$role] ?? count($order);
    }

    /**
     * The roles a seat covers beyond its primary one, as display names.
     *
     * @param  array<string, mixed>  $seat
     * @return list<string>
     */
    private function alsoRoles(array $seat, ?string $primary): array
    {
        return collect($seat['roles'] ?? [])
            ->except((string) $primary)
            ->values()
            ->all();
    }

    /**
     * Assign each vehicle to one row, and count what that row owns.
     *
     * A vehicle sits on more than one line and is crewed once, so the first row
     * to show it in display order keeps the editor and counts it; the rest
     * carry it read-only, naming where it lives. The same rule the XP and
     * purchase boards use, and for the same reason — without it a tier VIII
     * under three tier Xs would offer three editors over one crew.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function claimShared(Collection $rows): Collection
    {
        $owner = [];

        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                $owner[$cell['tank_id']] ??= ['key' => $row['key'], 'name' => $row['name']];
            }
        }

        return $rows->map(function (array $row) use ($owner): array {
            foreach ($row['cells'] as $tier => $cell) {
                if ($owner[$cell['tank_id']]['key'] !== $row['key']) {
                    $row['cells'][$tier]['is_shared'] = true;
                    $row['cells'][$tier]['shared_with'] = $owner[$cell['tank_id']]['name'];
                }
            }

            $owned = collect($row['cells'])->reject(fn (array $cell): bool => $cell['is_shared']);

            // Cells this row is the one to answer for: how many of them hold a
            // crew, how many there are, and what is banked across them.
            $row['crews'] = $owned->where('has_crew', true)->count();
            $row['cells_owned'] = $owned->count();
            $row['banked_xp'] = (int) $owned->sum('banked_xp');

            return $row;
        });
    }

    /**
     * The headline figures, counted over owned cells so a vehicle on three
     * lines is counted once.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function totals(Collection $rows): array
    {
        $cells = $rows->flatMap(fn (array $row): array => array_values($row['cells']))
            ->reject(fn (array $cell): bool => $cell['is_shared']);

        $crewed = $cells->where('has_crew', true);

        return [
            'crews' => $crewed->count(),
            'max_crews' => $crewed->where('is_max', true)->count(),
            // Sets where every member is a zero-skill crew member — the green
            // the board is aiming at.
            'zero_skill_crews' => $crewed->where('zero_state', 'all')->count(),
            'banked_xp' => (int) $cells->sum('banked_xp'),
        ];
    }

    /**
     * Every tier holding a cell gets a column.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<int>
     */
    private function tierColumns(Collection $rows): array
    {
        return $rows
            ->flatMap(fn (array $r): array => array_keys($r['cells']))
            ->unique()
            ->sort()
            ->map(fn ($tier): int => (int) $tier)
            ->values()
            ->all();
    }
}
