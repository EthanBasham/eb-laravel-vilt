<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\AssignArmadaRequest;
use App\Http\Requests\Finance\SaveArmadaRequest;
use App\Models\Finance\Armada;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Services\Finance\ArmadaBoard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Armadas: the fleet in named parts, each with the holdings, income and
 * expenses that belong together.
 */
class ArmadaController extends Controller
{
    public function index(Request $request, ArmadaBoard $board): Response
    {
        return Inertia::render('Armadas', $board->listFor($request->user()));
    }

    public function show(Request $request, Armada $armada, ArmadaBoard $board): Response
    {
        abort_unless($armada->isOwnedBy($request->user()), 404);

        return Inertia::render('Armada', $board->for($request->user(), $armada));
    }

    public function store(SaveArmadaRequest $request): RedirectResponse
    {
        $armada = Armada::query()->create([...$request->validated(), 'user_id' => $request->user()->id]);

        // Straight into it: an armada is made in order to be filled.
        return to_route('finance.armadas.show', $armada)->with('success', "{$armada->name} launched. Add what belongs in it.");
    }

    public function update(SaveArmadaRequest $request, Armada $armada): RedirectResponse
    {
        abort_unless($armada->isOwnedBy($request->user()), 404);

        $armada->update($request->validated());

        return back(fallback: route('finance.armadas'))->with('success', "{$armada->name} updated.");
    }

    /**
     * Moves holdings and standalone flows into an armada, or out of all of
     * them. Only top-level rows carry an armada: an account inside another,
     * an item inside an expense and a flow hung off a holding follow their
     * owner, so they are left alone here.
     */
    public function assign(AssignArmadaRequest $request): RedirectResponse
    {
        $armadaId = $request->validated('armada_id');

        Holding::query()->onlyOwnedBy($request->user())->onlyTopLevel()
            ->whereIn('id', $request->validated('holdings', []))
            ->update(['armada_id' => $armadaId]);

        Flow::query()->onlyOwnedBy($request->user())->onlyTopLevel()->whereNull('holding_id')
            ->whereIn('id', $request->validated('flows', []))
            ->update(['armada_id' => $armadaId]);

        return back(fallback: route('finance.armadas'));
    }

    public function destroy(Request $request, Armada $armada): RedirectResponse
    {
        abort_unless($armada->isOwnedBy($request->user()), 404);

        $armada->delete();

        return to_route('finance.armadas')->with('success', "{$armada->name} disbanded. Everything in it is still in your fleet, unassigned.");
    }
}
