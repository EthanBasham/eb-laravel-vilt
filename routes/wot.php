<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Wot\AccountLinkController;
use App\Http\Controllers\Wot\BookmarkController;
use App\Http\Controllers\Wot\CalendarController;
use App\Http\Controllers\Wot\CrewController;
use App\Http\Controllers\Wot\DashboardController;
use App\Http\Controllers\Wot\GrindController;
use App\Http\Controllers\Wot\NewsController;

/*
* World of Tanks sub-project. Mounted at /wot by routes/web.php, which also
* applies the Inertia middleware and the auth guard to this whole group — every
* route here is personal to the signed-in user.
*/

// WOT Hub : Dashboard / Bookmarks
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/refresh', [DashboardController::class, 'refresh'])->name('dashboard.refresh');
Route::put('/bookmarks', [BookmarkController::class, 'update'])->name('bookmarks.update');

// WOT Hub : Connect
Route::prefix('connect')->group(function () {
    Route::get('/', [AccountLinkController::class, 'create'])->name('link.create');
    Route::get('/callback', [AccountLinkController::class, 'callback'])->name('link.callback');
    Route::delete('/', [AccountLinkController::class, 'destroy'])->name('link.destroy');
});

Route::get('/grinding', [GrindController::class, 'index'])->name('grinding');
Route::patch('/grinding/filters', [GrindController::class, 'updateFilters'])->name('grinding.filters');
// Bound by tank_id rather than by model: a purchase row is created on first
// use, so there is nothing to bind to until then.
Route::patch('/grinding/purchases/{tankId}', [GrindController::class, 'updatePurchase'])->name('grinding.purchase');
// Which nation is to pay for how many of a vehicle's blueprint fragments. The
// nation is in the path for the reason the blueprint stock below gives: it is
// part of what is being written, not a value written against it.
Route::patch('/grinding/purchases/{tankId}/blueprint-plan/{nation}', [GrindController::class, 'updateBlueprintPlan'])->name('grinding.blueprint-plan');
Route::patch('/grinding/modules/{tankId}', [GrindController::class, 'updateModulePlan'])->name('grinding.module-plan');
Route::patch('/grinding/modules/{tankId}/top-gun', [GrindController::class, 'planTopGun'])->name('grinding.top-gun');
// The plan spent rather than another module added to it, so it hangs off the
// plan's own path rather than the research ones — what it writes is research,
// but what you are telling it is that the Free XP has gone.
Route::patch('/grinding/modules/{tankId}/applied', [GrindController::class, 'applyModulePlan'])->name('grinding.module-plan-applied');
Route::patch('/grinding/research/{tankId}/modules', [GrindController::class, 'updateModuleResearch'])->name('grinding.research-module');
Route::patch('/grinding/research/{tankId}/modules/all', [GrindController::class, 'researchAllModules'])->name('grinding.research-all');
Route::patch('/grinding/research/{tankId}', [GrindController::class, 'updateResearchXp'])->name('grinding.research-xp');
// Blueprints held, per nation — keyed by the nation rather than a tank, since
// they are not spent on any vehicle yet. 'universal' stands in for a nation.
Route::patch('/grinding/blueprints/{nation}', [GrindController::class, 'updateBlueprintStock'])->name('grinding.blueprint-stock');

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

// WOT Hub : News
Route::prefix('news')->group(function () {
    Route::get('/', [NewsController::class, 'index'])->name('news.index');
    Route::post('/resync', [NewsController::class, 'resync'])->name('news.resync');
    Route::post('/mark-all-seen', [NewsController::class, 'markAllSeen'])->name('news.articles.mark-all-seen');
    Route::post('/{article}/mark-seen', [NewsController::class, 'markSeen'])->name('news.articles.mark-seen');
    Route::post('/{article}/pin', [NewsController::class, 'pin'])->name('news.articles.pin');
    Route::delete('/{article}/pin', [NewsController::class, 'unpin'])->name('news.articles.unpin');
});

// WOT Hub : Calendar
Route::prefix('calendar')->group(function () {
    Route::get('/', [CalendarController::class, 'calendar'])->name('calendar');
    Route::post('/events/{event}/ignore', [CalendarController::class, 'ignore'])->name('calendar.events.ignore');
    Route::delete('/events/{event}/ignore', [CalendarController::class, 'unignore'])->name('calendar.events.unignore');
});
