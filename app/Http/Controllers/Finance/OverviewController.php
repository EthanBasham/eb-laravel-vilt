<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Finance\OverviewBoard;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function index(Request $request, OverviewBoard $board): Response
    {
        return Inertia::render('Overview', $board->for($request->user()));
    }
}
