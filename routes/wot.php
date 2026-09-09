<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Wot\AccountLinkController;
use App\Http\Controllers\Wot\DashboardController;
use App\Http\Controllers\Wot\GrindController;

/*
 * World of Tanks sub-project. Mounted at /wot by routes/web.php, which also
 * applies the Inertia middleware and the auth guard to this whole group — every
 * route here is personal to the signed-in user.
 */

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/grinds', [GrindController::class, 'index'])->name('grinds.index');
Route::post('/grinds', [GrindController::class, 'store'])->name('grinds.store');
Route::delete('/grinds/{grind}', [GrindController::class, 'destroy'])->name('grinds.destroy');

Route::get('/connect', [AccountLinkController::class, 'create'])->name('link.create');
Route::get('/connect/callback', [AccountLinkController::class, 'callback'])->name('link.callback');
Route::delete('/connect', [AccountLinkController::class, 'destroy'])->name('link.destroy');
