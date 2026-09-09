<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Wot\AccountLinkController;
use App\Http\Controllers\Wot\DashboardController;
use App\Http\Controllers\Wot\GrindController;
use App\Http\Controllers\Wot\NewsController;

/*
 * World of Tanks sub-project. Mounted at /wot by routes/web.php, which also
 * applies the Inertia middleware and the auth guard to this whole group — every
 * route here is personal to the signed-in user.
 */

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/grinding', [GrindController::class, 'index'])->name('grinding');
Route::post('/grinding/targets', [GrindController::class, 'store'])->name('grinding.store');
Route::delete('/grinding/targets/{target}', [GrindController::class, 'destroy'])->name('grinding.destroy');
Route::patch('/grinding/targets/{target}/complete', [GrindController::class, 'complete'])->name('grinding.complete');
Route::patch('/grinding/steps/{step}', [GrindController::class, 'updateStep'])->name('grinding.step');
Route::patch('/grinding/steps/{step}/modules', [GrindController::class, 'updateModule'])->name('grinding.module');
Route::patch('/grinding/settings', [GrindController::class, 'updateSettings'])->name('grinding.settings');
// Bound by tank_id rather than by model: a purchase row is created on first
// use, so there is nothing to bind to until then.
Route::patch('/grinding/purchases/{tankId}', [GrindController::class, 'updatePurchase'])->name('grinding.purchase');

Route::post('/refresh', [DashboardController::class, 'refresh'])->name('dashboard.refresh');

Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/calendar', [NewsController::class, 'calendar'])->name('calendar');

// Pins are per user, so these live under the authenticated group like
// everything else here.
Route::post('/news/seen', [NewsController::class, 'markSeen'])->name('news.seen');
Route::post('/news/seen-all', [NewsController::class, 'markAllSeen'])->name('news.seen-all');
Route::post('/news/{article}/pin', [NewsController::class, 'pin'])->name('news.pin');
Route::delete('/news/{article}/pin', [NewsController::class, 'unpin'])->name('news.unpin');

Route::get('/connect', [AccountLinkController::class, 'create'])->name('link.create');
Route::get('/connect/callback', [AccountLinkController::class, 'callback'])->name('link.callback');
Route::delete('/connect', [AccountLinkController::class, 'destroy'])->name('link.destroy');
