<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\UpdateMilestoneRequest;
use App\Models\Milestone;
use App\Models\Project;

class MilestoneController extends Controller
{
    /**
     * Toggle a milestone's completion.
     *
     * Answers JSON to the jQuery handler in resources/js/app.js and a redirect
     * to a plain form post, so the feature works either way.
     */
    public function update(UpdateMilestoneRequest $request, Project $project, Milestone $milestone): JsonResponse|RedirectResponse
    {
        // Scoped bindings would enforce this automatically, but only when the
        // parent is bound by its default key — this route binds the project by
        // slug, so the check is explicit.
        abort_unless($milestone->project_id === $project->id, 404);

        $milestone->markComplete($request->boolean('is_complete'));

        if ($request->expectsJson()) {
            return response()->json([
                'is_complete' => $milestone->is_complete,
                'progress_label' => $project->load('milestones')->progressLabel(),
            ]);
        }

        return back();
    }
}
