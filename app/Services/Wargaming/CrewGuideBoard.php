<?php

namespace App\Services\Wargaming;

use App\Models\WotAccount;
use App\Models\WotCrewGuide;
use App\Models\WotCrewGuideRole;

/**
 * The Guide tab: every guide the account keeps, and the catalogue of perks
 * they are drawn from.
 *
 * Only what a role includes is sent. The page works out the other bucket
 * itself, from `role_perks`, because it has to anyway: a perk dragged across
 * changes both buckets before the server has heard about it.
 */
class CrewGuideBoard
{
    /**
     * @return array{
     *     guides: list<array{id: int, name: string, roles: array<string, array{included: list<string>, notes: string|null}>}>,
     *     perks: array<string, array{name: string, description: string|null}>,
     *     role_perks: array<string, list<string>>,
     * }
     */
    public function for(WotAccount $account): array
    {
        $rolePerks = (array) config('wargaming.crew_role_perks');

        return [
            'guides' => $account->crewGuides()
                ->with('roles')
                ->inDefaultOrder()
                ->get()
                ->map(fn (WotCrewGuide $guide): array => [
                    'id' => $guide->id,
                    'name' => $guide->name,
                    'roles' => collect($rolePerks)
                        ->map(fn (array $trainable, string $role): array => $this->role(
                            $trainable,
                            $guide->roles->firstWhere('role', $role),
                        ))
                        ->all(),
                ])
                ->all(),
            'perks' => (array) config('wargaming.crew_perks'),
            'role_perks' => $rolePerks,
        ];
    }

    /**
     * What a guide says for one role, or the blank answer where nothing has
     * been saved for it.
     *
     * Included keeps the order it was saved in. A stored perk the role can no
     * longer train — one a patch took away — is dropped rather than shown as
     * something to take.
     *
     * @param  list<string>  $trainable
     * @return array{included: list<string>, notes: string|null}
     */
    private function role(array $trainable, ?WotCrewGuideRole $saved): array
    {
        return [
            'included' => array_values(array_intersect($saved->included ?? [], $trainable)),
            'notes' => $saved?->notes,
        ];
    }
}
