<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Finance\RealEstateAnalyzer;
use Inertia\Inertia;
use Inertia\Response;

class RealEstateController extends Controller
{
    public function index(Request $request, RealEstateAnalyzer $analyzer): Response
    {
        return Inertia::render('RealEstate', $analyzer->for($request->user(), $request->query()));
    }
}
