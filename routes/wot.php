<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Wot\AccountLinkController;
use App\Http\Controllers\Wot\CrewController;
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
Route::patch('/grinding/filters', [GrindController::class, 'updateFilters'])->name('grinding.filters');
// Bound by tank_id rather than by model: a purchase row is created on first
// use, so there is nothing to bind to until then.
Route::patch('/grinding/purchases/{tankId}', [GrindController::class, 'updatePurchase'])->name('grinding.purchase');
Route::patch('/grinding/modules/{tankId}', [GrindController::class, 'updateModulePlan'])->name('grinding.module-plan');
Route::patch('/grinding/modules/{tankId}/top-gun', [GrindController::class, 'planTopGun'])->name('grinding.top-gun');
Route::patch('/grinding/research/{tankId}/modules', [GrindController::class, 'updateModuleResearch'])->name('grinding.research-module');
Route::patch('/grinding/research/{tankId}/modules/all', [GrindController::class, 'researchAllModules'])->name('grinding.research-all');
Route::patch('/grinding/research/{tankId}', [GrindController::class, 'updateResearchXp'])->name('grinding.research-xp');

/*
 * Crews. Four tabs over one page, like the grinding board: the tech-tree board
 * itself, the stockpiles behind it, the Battle Pass roster, and a guide yet to
 * be written.
 *
 * Nothing under here is fetched from Wargaming. The public API has no endpoint
 * for a player's own tankmen at all, so every route below writes something
 * typed in by hand.
 */
Route::get('/crews', [CrewController::class, 'index'])->name('crews');
Route::patch('/crews/filters', [CrewController::class, 'updateFilters'])->name('crews.filters');

// Bound by tank_id rather than by model, like the grinding writes: a crew row
// is created on first use, so there is nothing to bind to until then. PUT
// rather than PATCH because the editor sends the whole set — see updateCrew.
Route::put('/crews/tanks/{tankId}', [CrewController::class, 'updateCrew'])->name('crews.tank');
Route::delete('/crews/tanks/{tankId}', [CrewController::class, 'destroyCrew'])->name('crews.tank.destroy');

Route::patch('/crews/recruits/{recruitKey}', [CrewController::class, 'updateRecruit'])->name('crews.recruit');
// The nation is part of the identity of a stack of books, so it is part of the
// path rather than of the payload. 'universal' stands where a nation would be
// for a book that spends anywhere, and for the two special items.
Route::patch('/crews/books/{bookType}/{nation}', [CrewController::class, 'updateBook'])->name('crews.book');

Route::post('/crews/battle-pass', [CrewController::class, 'storeBattlePassCrew'])->name('crews.battle-pass.store');
Route::patch('/crews/battle-pass/{crew}', [CrewController::class, 'updateBattlePassCrew'])->name('crews.battle-pass.update');
Route::delete('/crews/battle-pass/{crew}', [CrewController::class, 'destroyBattlePassCrew'])->name('crews.battle-pass.destroy');

Route::post('/refresh', [DashboardController::class, 'refresh'])->name('dashboard.refresh');

Route::get('/news', [NewsController::class, 'index'])->name('news.index');
Route::get('/calendar', [NewsController::class, 'calendar'])->name('calendar');

// Pins are per user, so these live under the authenticated group like
// everything else here.
Route::post('/news/seen', [NewsController::class, 'markSeen'])->name('news.seen');
Route::post('/news/seen-all', [NewsController::class, 'markAllSeen'])->name('news.seen-all');
Route::post('/news/{article}/pin', [NewsController::class, 'pin'])->name('news.pin');
Route::delete('/news/{article}/pin', [NewsController::class, 'unpin'])->name('news.unpin');
Route::post('/news/resync', [NewsController::class, 'resync'])->name('news.resync');

// Ignoring is per user, like pins: it hides an event from this person's
// schedule views without touching the shared row everyone else reads.
Route::post('/events/{event}/ignore', [NewsController::class, 'ignore'])->name('events.ignore');
Route::delete('/events/{event}/ignore', [NewsController::class, 'unignore'])->name('events.unignore');

Route::get('/connect', [AccountLinkController::class, 'create'])->name('link.create');
Route::get('/connect/callback', [AccountLinkController::class, 'callback'])->name('link.callback');
Route::delete('/connect', [AccountLinkController::class, 'destroy'])->name('link.destroy');
