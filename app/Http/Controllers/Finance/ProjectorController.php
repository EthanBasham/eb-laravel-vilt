<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Finance\ProjectorBoard;
use Inertia\Inertia;
use Inertia\Response;

class ProjectorController extends Controller
{
    public function index(Request $request, ProjectorBoard $board): Response
    {
        return Inertia::render('Projector', $board->for($request->user(), $request->query()));
    }
}
