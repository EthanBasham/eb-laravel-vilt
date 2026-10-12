<?php

namespace App\Http\Controllers\Wot;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\WotEvent;
use App\Services\WotNews\CalendarBoard;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function calendar(Request $request, CalendarBoard $board): Response
    {
        $month = carbonify($request->query('month'), now())->startOfMonth();

        return Inertia::render('Calendar', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'previousMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
            ...$board->for($month, $request->user()),
        ]);
    }

    public function ignore(Request $request, WotEvent $event): RedirectResponse
    {
        $event->ignoreBy($request->user());

        return back(fallback: route('wot.calendar'));
    }
    public function unignore(Request $request, WotEvent $event): RedirectResponse
    {
        $event->unignoreBy($request->user());

        return back(fallback: route('wot.calendar'));
    }
}
