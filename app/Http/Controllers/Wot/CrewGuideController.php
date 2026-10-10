<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wot\SaveCrewGuideRequest;
use App\Http\Requests\Wot\SaveCrewGuideRoleRequest;
use App\Models\WotCrewGuide;

/**
 * The Guide tab of the Crews page: named guides, and what each role in one
 * should train.
 *
 * The page itself is CrewController::index, which this redirects back to. A
 * guide belonging to another account answers 404 rather than 403 throughout,
 * as the Battle Pass roster does: it is not something this user should be able
 * to confirm the existence of.
 */
class CrewGuideController extends Controller
{
    public function store(SaveCrewGuideRequest $request): RedirectResponse
    {
        $account = $request->user()->wotAccount;

        abort_unless($account, 404);

        $account->crewGuides()->create($request->validated());

        return back(fallback: route('wot.crews'));
    }

    public function update(SaveCrewGuideRequest $request, WotCrewGuide $guide): RedirectResponse
    {
        abort_unless($guide->wot_account_id === $request->user()->wotAccount?->id, 404);

        $guide->update($request->validated());

        return back(fallback: route('wot.crews'));
    }

    public function destroy(Request $request, WotCrewGuide $guide): RedirectResponse
    {
        abort_unless($guide->wot_account_id === $request->user()->wotAccount?->id, 404);

        $guide->delete();

        return back(fallback: route('wot.crews'));
    }

    /**
     * Saves one role's perks or its note.
     *
     * Which role is in the path, and the route only matches the five there
     * are.
     */
    public function updateRole(SaveCrewGuideRoleRequest $request, WotCrewGuide $guide, string $role): RedirectResponse
    {
        abort_unless($guide->wot_account_id === $request->user()->wotAccount?->id, 404);

        $guide->saveRole($role, $request->validated());

        return back(fallback: route('wot.crews'));
    }
}
