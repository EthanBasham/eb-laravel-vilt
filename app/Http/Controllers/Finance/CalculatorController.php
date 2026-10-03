<?php

namespace App\Http\Controllers\Finance;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Finance\Calculators;
use Inertia\Inertia;
use Inertia\Response;

class CalculatorController extends Controller
{
    public function index(Request $request, Calculators $calculators, string $tool = 'mortgage'): Response
    {
        abort_unless(array_key_exists($tool, Calculators::TOOLS), 404);

        return Inertia::render('Calculators', [
            'tool' => $tool,
            'tools' => Calculators::TOOLS,
            'result' => $calculators->{$tool}($request->query()),
        ]);
    }
}
