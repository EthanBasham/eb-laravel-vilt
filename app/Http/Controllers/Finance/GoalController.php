<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SaveGoalRequest;
use App\Models\Finance\Goal;
use App\Services\Finance\GoalPlanner;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function index(Request $request, GoalPlanner $planner): Response
    {
        return Inertia::render('Goals', $planner->for($request->user(), $request->query()));
    }

    public function store(SaveGoalRequest $request): RedirectResponse
    {
        $goal = Goal::query()->create([...$request->validated(), 'user_id' => $request->user()->id]);

        return back(fallback: route('finance.goals'))->with('success', "{$goal->name} added.");
    }

    public function update(SaveGoalRequest $request, Goal $goal): RedirectResponse
    {
        abort_unless($goal->isOwnedBy($request->user()), 404);

        $goal->update($request->validated());

        return back(fallback: route('finance.goals'))->with('success', "{$goal->name} updated.");
    }

    public function destroy(Request $request, Goal $goal): RedirectResponse
    {
        abort_unless($goal->isOwnedBy($request->user()), 404);

        $goal->delete();

        return back(fallback: route('finance.goals'))->with('success', "{$goal->name} removed.");
    }
}
