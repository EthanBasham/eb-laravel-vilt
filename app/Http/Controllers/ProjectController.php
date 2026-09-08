<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use App\Models\Project;

class ProjectController extends Controller
{
    public function index(): View
    {
        return view('projects.index', [
            'projects' => $this->publishedProjects(),
        ]);
    }
    public function show(Project $project): View
    {
        // Route model binding resolves drafts and future-dated projects too —
        // the scope only guards the listing queries, not the binding.
        abort_unless($project->is_published, 404);

        return view('projects.show', [
            'project' => $project->load('milestones'),
        ]);
    }
    private function publishedProjects(): LengthAwarePaginator
    {
        return Project::onlyPublished()
            ->inDefaultOrder()
            ->withCount([
                'milestones',
                'milestones as completed_milestones_count' => fn ($query) => $query->onlyComplete(),
            ])
            ->paginate(9);
    }
}
