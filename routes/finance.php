<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Finance\ArmadaController;
use App\Http\Controllers\Finance\BudgetController;
use App\Http\Controllers\Finance\CalculatorController;
use App\Http\Controllers\Finance\ConversionStrategyController;
use App\Http\Controllers\Finance\FleetController;
use App\Http\Controllers\Finance\FlowController;
use App\Http\Controllers\Finance\GoalController;
use App\Http\Controllers\Finance\MonteCarloController;
use App\Http\Controllers\Finance\OverviewController;
use App\Http\Controllers\Finance\PositionController;
use App\Http\Controllers\Finance\ProjectorController;
use App\Http\Controllers\Finance\RealEstateController;
use App\Http\Controllers\Finance\RealityController;
use App\Http\Controllers\Finance\RetirementController;
use App\Http\Controllers\Finance\ScenarioController;
use App\Http\Controllers\Finance\ScenarioFlowController;
use App\Http\Controllers\Finance\ScenarioHoldingController;
use App\Http\Controllers\Finance\SettingsController;
use App\Http\Controllers\Finance\SocialSecurityController;
use App\Http\Controllers\Finance\TransferController;
use App\Http\Controllers\Finance\WithdrawalStrategyController;

/*
 * The Financial Fleet sub-project. Mounted at /finance by routes/web.php,
 * which also applies its Inertia middleware and the auth guard to the whole
 * group — every route here is personal to the signed-in user.
 *
 * Two kinds of route: the fleet itself (holdings, positions, flows, scenarios,
 * goals, snapshots), which is ordinary CRUD, and the tools, which are all GETs that
 * render from the query string so a set of inputs is a URL you can keep.
 */

Route::get('/', [OverviewController::class, 'index'])->name('overview');

// The fleet: assets and liabilities, and what sits inside an account.
Route::get('/fleet', [FleetController::class, 'index'])->name('fleet');
Route::post('/fleet', [FleetController::class, 'store'])->name('fleet.store');
Route::get('/fleet/{holding}', [FleetController::class, 'show'])->name('fleet.show');
Route::patch('/fleet/{holding}', [FleetController::class, 'update'])->name('fleet.update');
Route::delete('/fleet/{holding}', [FleetController::class, 'destroy'])->name('fleet.destroy');

Route::post('/fleet/{holding}/positions', [PositionController::class, 'store'])->name('positions.store');
Route::patch('/positions/{position}', [PositionController::class, 'update'])->name('positions.update');
Route::delete('/positions/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');

// Armadas: the fleet in named parts. `assign` comes before the wildcard so it
// is not read as an armada's id.
Route::get('/armadas', [ArmadaController::class, 'index'])->name('armadas');
Route::post('/armadas', [ArmadaController::class, 'store'])->name('armadas.store');
Route::put('/armadas/assign', [ArmadaController::class, 'assign'])->name('armadas.assign');
Route::get('/armadas/{armada}', [ArmadaController::class, 'show'])->name('armadas.show');
Route::patch('/armadas/{armada}', [ArmadaController::class, 'update'])->name('armadas.update');
Route::delete('/armadas/{armada}', [ArmadaController::class, 'destroy'])->name('armadas.destroy');

// Income and expenses.
Route::get('/cashflow', [FlowController::class, 'index'])->name('cashflow');
Route::post('/flows', [FlowController::class, 'store'])->name('flows.store');
Route::patch('/flows/{flow}', [FlowController::class, 'update'])->name('flows.update');
Route::delete('/flows/{flow}', [FlowController::class, 'destroy'])->name('flows.destroy');

// Automated transfers, listed on the income & expenses page.
Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
Route::patch('/transfers/{transfer}', [TransferController::class, 'update'])->name('transfers.update');
Route::delete('/transfers/{transfer}', [TransferController::class, 'destroy'])->name('transfers.destroy');

// Projections & scenarios: what each flow is assumed to do from here on.
Route::get('/scenarios', [ScenarioController::class, 'index'])->name('scenarios');
Route::post('/scenarios', [ScenarioController::class, 'store'])->name('scenarios.store');
Route::get('/scenarios/{scenario}', [ScenarioController::class, 'show'])->name('scenarios.show');
Route::patch('/scenarios/{scenario}', [ScenarioController::class, 'update'])->name('scenarios.update');
Route::delete('/scenarios/{scenario}', [ScenarioController::class, 'destroy'])->name('scenarios.destroy');
Route::post('/scenarios/{scenario}/duplicate', [ScenarioController::class, 'duplicate'])->name('scenarios.duplicate');
// PUT: a scenario's settings for one flow are written whole, like an actual.
Route::put('/scenarios/{scenario}/flows/{flow}', [ScenarioFlowController::class, 'update'])->name('scenarios.flows.update');
Route::put('/scenarios/{scenario}/holdings/{holding}', [ScenarioHoldingController::class, 'update'])->name('scenarios.holdings.update');
Route::put('/scenarios/{scenario}/rates', [ScenarioFlowController::class, 'updateRates'])->name('scenarios.rates.update');

Route::get('/budget', [BudgetController::class, 'index'])->name('budget');
// PUT, keyed by the flow: one figure per flow per month, written whole. The
// month travels in the body because it is part of what is being recorded.
Route::put('/budget/actuals/{flow}', [BudgetController::class, 'updateActual'])->name('budget.actual');

Route::get('/goals', [GoalController::class, 'index'])->name('goals');
Route::post('/goals', [GoalController::class, 'store'])->name('goals.store');
Route::patch('/goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
Route::delete('/goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');

Route::get('/reality', [RealityController::class, 'index'])->name('reality');
Route::post('/reality/snapshots', [RealityController::class, 'store'])->name('snapshots.store');
Route::delete('/reality/snapshots/{snapshot}', [RealityController::class, 'destroy'])->name('snapshots.destroy');

// The tools. Read-only, and driven entirely by the query string.
Route::get('/projector', [ProjectorController::class, 'index'])->name('projector');
Route::get('/real-estate', [RealEstateController::class, 'index'])->name('real-estate');
// The Retirement Strategizer is tabs, one tool to a tab; each tool's strategies
// are saved rows.
Route::get('/retirement/{tab?}', [RetirementController::class, 'index'])->whereIn('tab', ['conversions', 'social-security', 'withdrawals'])->name('retirement');
Route::put('/retirement/social-security/benefits', [SocialSecurityController::class, 'updateBenefits'])->name('retirement.social-security.benefits');
Route::post('/retirement/social-security/strategies', [SocialSecurityController::class, 'store'])->name('retirement.social-security.store');
Route::post('/retirement/social-security/strategies/starters', [SocialSecurityController::class, 'storeStarters'])->name('retirement.social-security.starters');
Route::patch('/retirement/social-security/strategies/{strategy}', [SocialSecurityController::class, 'update'])->name('retirement.social-security.update');
Route::post('/retirement/social-security/strategies/{strategy}/apply', [SocialSecurityController::class, 'apply'])->name('retirement.social-security.apply');
Route::delete('/retirement/social-security/strategies/{strategy}', [SocialSecurityController::class, 'destroy'])->name('retirement.social-security.destroy');
Route::post('/retirement/withdrawals/strategies', [WithdrawalStrategyController::class, 'store'])->name('retirement.withdrawals.store');
Route::post('/retirement/withdrawals/strategies/starters', [WithdrawalStrategyController::class, 'storeStarters'])->name('retirement.withdrawals.starters');
Route::patch('/retirement/withdrawals/strategies/{strategy}', [WithdrawalStrategyController::class, 'update'])->name('retirement.withdrawals.update');
Route::post('/retirement/withdrawals/strategies/{strategy}/duplicate', [WithdrawalStrategyController::class, 'duplicate'])->name('retirement.withdrawals.duplicate');
Route::delete('/retirement/withdrawals/strategies/{strategy}', [WithdrawalStrategyController::class, 'destroy'])->name('retirement.withdrawals.destroy');
Route::post('/retirement/strategies', [ConversionStrategyController::class, 'store'])->name('retirement.strategies.store');
Route::post('/retirement/strategies/starters', [ConversionStrategyController::class, 'storeStarters'])->name('retirement.strategies.starters');
// Which strategies are set side by side. On the comparison as a whole, PUT
// replaces it and DELETE empties it; the other two move one strategy in or
// out. The whole-comparison routes come before the wildcard ones so that
// "comparison" is not read as a strategy's id.
Route::put('/retirement/strategies/comparison', [ConversionStrategyController::class, 'replaceComparison'])->name('retirement.strategies.comparison');
Route::delete('/retirement/strategies/comparison', [ConversionStrategyController::class, 'clearComparison'])->name('retirement.strategies.comparison.clear');
Route::post('/retirement/strategies/{strategy}/compare', [ConversionStrategyController::class, 'compare'])->name('retirement.strategies.compare');
Route::delete('/retirement/strategies/{strategy}/compare', [ConversionStrategyController::class, 'hold'])->name('retirement.strategies.hold');
Route::patch('/retirement/strategies/{strategy}', [ConversionStrategyController::class, 'update'])->name('retirement.strategies.update');
Route::post('/retirement/strategies/{strategy}/duplicate', [ConversionStrategyController::class, 'duplicate'])->name('retirement.strategies.duplicate');
Route::delete('/retirement/strategies/{strategy}', [ConversionStrategyController::class, 'destroy'])->name('retirement.strategies.destroy');
Route::put('/retirement/monte-carlo', [MonteCarloController::class, 'update'])->name('retirement.monte-carlo.update');
Route::post('/retirement/monte-carlo/run', [MonteCarloController::class, 'run'])->name('retirement.monte-carlo.run');
Route::post('/retirement/monte-carlo/reshuffle', [MonteCarloController::class, 'reshuffle'])->name('retirement.monte-carlo.reshuffle');
Route::get('/calculators/{tool?}', [CalculatorController::class, 'index'])->name('calculators');

Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/sample', [SettingsController::class, 'loadSample'])->name('sample.load');
Route::delete('/sample', [SettingsController::class, 'clear'])->name('sample.clear');
