<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveConversionStrategyRequest;
use App\Models\Finance\ConversionStrategy;

/**
 * The strategies the Retirement Strategizer compares. Ordinary CRUD: the
 * figures are worked out when the page is rendered, by ConversionBoard.
 */
class ConversionStrategyController extends Controller
{
    public function store(SaveConversionStrategyRequest $request): RedirectResponse
    {
        $strategy = ConversionStrategy::query()->create([...$request->validated(), 'user_id' => $request->user()->id]);

        return back(fallback: route('finance.retirement'))->with('success', "{$strategy->name} added.");
    }

    /**
     * One strategy of each kind, on every default, so there is something to
     * compare before anything has been decided.
     */
    public function storeStarters(Request $request): RedirectResponse
    {
        DB::transaction(fn () => collect(config('finance.conversion_strategies'))->each(fn (array $kind, string $key) => ConversionStrategy::query()->create([
            'user_id' => $request->user()->id,
            'name' => $kind['label'],
            'kind' => $key,
            'heir_income' => config('finance.defaults.heir_income'),
        ])));

        return back(fallback: route('finance.retirement'))->with('success', 'One strategy of each kind added. Edit any of them, or copy one to try a variation.');
    }

    public function update(SaveConversionStrategyRequest $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->update($request->validated());

        return back(fallback: route('finance.retirement'))->with('success', "{$strategy->name} updated.");
    }

    public function duplicate(Request $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $copy = $strategy->replicate()->fill(['name' => str("{$strategy->name} copy")->limit(80, '')->toString()]);
        $copy->save();

        return back(fallback: route('finance.retirement'))->with('success', "{$copy->name} made from {$strategy->name}.");
    }

    public function destroy(Request $request, ConversionStrategy $strategy): RedirectResponse
    {
        abort_unless($strategy->isOwnedBy($request->user()), 404);

        $strategy->delete();

        return back(fallback: route('finance.retirement'))->with('success', "{$strategy->name} removed.");
    }
}
