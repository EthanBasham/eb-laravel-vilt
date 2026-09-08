<?php

namespace App\Http\Controllers;

use Illuminate\View\View;
use App\Models\Project;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('home', [
            'featured' => Project::onlyPublished()
                ->onlyFeatured()
                ->inDefaultOrder()
                ->withCount([
                    'milestones',
                    'milestones as completed_milestones_count' => fn ($query) => $query->onlyComplete(),
                ])
                ->get(),
        ]);
    }
}
