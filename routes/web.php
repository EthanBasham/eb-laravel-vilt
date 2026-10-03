<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\HandleFinanceInertiaRequests;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PipelineCheckController;
use App\Http\Controllers\ProfileController;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Throttled because it's an unauthenticated write endpoint, even though it
// writes nothing — there's no reason to let it be hammered.
Route::post('/pipeline-check', [PipelineCheckController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('pipeline-check.store');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->middleware('verified')->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
 * The World of Tanks sub-project — the one Inertia + Vue island in an otherwise
 * Blade application. HandleInertiaRequests is applied here rather than globally
 * so the rest of the site keeps rendering plain Blade with no Inertia headers or
 * asset-version handshake.
 */
Route::middleware(['auth', HandleInertiaRequests::class])
    ->prefix('wot')
    ->name('wot.')
    ->group(base_path('routes/wot.php'));

/*
 * The Financial Fleet sub-project — a second Inertia + Vue island, with its own
 * middleware, root view (finance.blade.php) and Vite entry
 * (resources/js/finance/app.js). Self-contained: see the 2026-10-01 entry in
 * docs/setup-log.md for the list of everything it added and how to remove it.
 */
Route::middleware(['auth', HandleFinanceInertiaRequests::class])
    ->prefix('finance')
    ->name('finance.')
    ->group(base_path('routes/finance.php'));

require __DIR__.'/auth.php';
