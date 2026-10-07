<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveSocialSecurityBenefitsRequest;
use App\Http\Requests\Finance\SaveSocialSecurityStrategyRequest;
use App\Models\Finance\Profile;
use App\Models\Finance\SocialSecurityStrategy;
use App\Services\Finance\SocialSecurityBoard;
use App\Services\Finance\SocialSecurityFlows;

/**
 * The Social Security tab's strategies, and the benefits they are worked
 * from. The figures are worked out when the page is rendered, by
 * SocialSecurityBoard.
 */
class SocialSecurityController extends Controller
{
    /**
     * Each person's benefit at full retirement age, and the spouse's dates.
     * Facts about the household, so they are saved on the profile.
     */
    public function updateBenefits(SaveSocialSecurityBenefitsRequest $request): RedirectResponse
    {
        Profile::saveFor($request->user(), $request->validated());

        return back(fallback: route('finance.retirement', 'social-security'))->with('success', 'Benefits saved.');
    }

    public function store(SaveSocialSecurityStrategyRequest $request): RedirectResponse
    {
        $strategy = SocialSecurityStrategy::query()->create([...$request->validated(), 'user_id' => $request->user()->id]);

        return back(fallback: route('finance.retirement', 'social-security'))->with('success', "{$strategy->name} added.");
    }

    /**
     * The three ages worth setting side by side before anything has been
     * decided: as early as possible, at full retirement age, and at 70.
     */
    public function storeStarters(Request $request, SocialSecurityBoard $board): RedirectResponse
    {
        SocialSecurityStrategy::createStarters($request->user(), $board->people(Profile::for($request->user())));

        return back(fallback: route('finance.retirement', 'social-security'))->with('success', 'Three claiming ages added. Edit any of them, or build one of your own.');
    }

    public function update(SaveSocialSecurityStrategyRequest $request, SocialSecurityStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->update($request->validated());

        return back(fallback: route('finance.retirement', 'social-security'))->with('success', "{$strategy->name} updated.");
    }

    /**
     * Writes the strategy into Income & expenses, so the projections and the
     * other retirement tools run on it.
     */
    public function apply(Request $request, SocialSecurityStrategy $strategy, SocialSecurityFlows $flows): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $written = $flows->write($request->user(), $strategy);

        if ($written === 0) {
            return back(fallback: route('finance.retirement', 'social-security'))->with('error', 'There is no benefit to write yet. Enter a monthly benefit first.');
        }

        return back(fallback: route('finance.retirement', 'social-security'))->with('success', "{$strategy->name} is now your Social Security income. Every projection and retirement tool runs on it.");
    }

    public function destroy(Request $request, SocialSecurityStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->delete();

        return back(fallback: route('finance.retirement', 'social-security'))->with('success', "{$strategy->name} removed.");
    }
}
