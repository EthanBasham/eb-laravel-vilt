<?php

use Illuminate\Support\Facades\Hash;
use App\Models\Finance\ConversionReportEntry;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Flow;
use App\Models\Finance\Goal;
use App\Models\Finance\Holding;
use App\Models\Finance\MonteCarloRun;
use App\Models\Finance\Position;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;
use App\Models\Finance\Transfer;
use App\Models\User;
use App\Models\WotAccount;
use App\Models\WotBookmark;
use App\Models\WotCrewGuide;
use App\Models\WotCrewMember;
use App\Models\WotTankCrew;
use App\Models\WotTankPurchase;

beforeEach(function () {
    config(['guest.password' => 'guest-password', 'guest.finance.scale' => 1.5]);
});

/** The owner whose data the guest is copied from. */
function guestSource(): User
{
    $owner = User::factory()->create();
    config(['guest.source_user_id' => $owner->id]);

    return $owner;
}

function guestUser(): User
{
    return User::query()->where('email', 'guest')->firstOrFail();
}

/**
 * A small fleet carrying a figure, a name and a reference in each place the
 * copy has to change one.
 *
 * @return array<string, mixed>
 */
function ownerFleet(User $owner): array
{
    Profile::query()->create(['user_id' => $owner->id, 'ss_monthly_benefit' => 2000]);

    $roth = Holding::factory()->for($owner)->retirement('roth', 'ira')->create(['name' => 'My Roth', 'balance' => 0]);
    $inside = Holding::factory()->inside($roth)->create(['name' => 'Real Bank brokerage', 'institution' => 'Real Bank', 'notes' => 'Account 1234', 'balance' => 1000, 'monthly_contribution' => 100]);
    Position::query()->create(['holding_id' => $inside->id, 'name' => 'Fidelity 500', 'symbol' => 'FXAIX', 'asset_class' => 'index_fund', 'value' => 400]);

    $home = Holding::factory()->for($owner)->ofType('real_estate')->create(['name' => '12 Elm St', 'balance' => 300000]);
    $mortgage = Holding::factory()->for($owner)->liability()->create(['name' => 'Elm St mortgage', 'balance' => 200000, 'secured_by_id' => $home->id]);

    $salary = Flow::factory()->for($owner)->income('salary')->create(['name' => 'BRI Salary', 'amount' => 5000, 'account_id' => $inside->id]);
    $household = Flow::factory()->for($owner)->create(['category' => 'household', 'name' => 'Our household', 'amount' => 0]);
    $groceries = Flow::factory()->for($owner)->create(['category' => 'food', 'name' => 'Costco run', 'amount' => 22.99, 'parent_id' => $household->id]);

    $scenario = Scenario::factory()->for($owner)->create(['name' => 'BRI shuts down', 'description' => 'BRI closes over 5y']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $salary->id, 'overrides' => ['2032' => 100000]]);

    $strategy = ConversionStrategy::factory()->for($owner)->create(['name' => 'Convert at 60', 'kind' => 'fixed', 'conversion_amount' => 10000, 'overrides' => ['2030' => ['conversion' => 20000, 'withheld' => null]]]);
    ConversionReportEntry::factory()->create(['conversion_strategy_id' => $strategy->id, 'scenario_id' => $scenario->id]);

    Transfer::factory()->for($owner)->create(['name' => 'Pay down Elm St', 'kind' => 'fixed', 'from_holding_id' => $inside->id, 'to_holding_id' => $mortgage->id, 'amount' => 300]);
    Goal::query()->create(['user_id' => $owner->id, 'name' => 'Pay for Emma\'s college', 'target_amount' => 80000, 'saved_amount' => 1000, 'target_date' => '2040-01-01']);

    return compact('inside', 'home', 'mortgage', 'salary', 'household', 'groceries');
}

it('creates a verified guest who signs in with the username and the configured password', function () {
    guestSource();

    $this->artisan('guest:refresh')->assertSuccessful();

    $this->post('/login', ['email' => 'guest', 'password' => 'guest-password']);
    $this->assertAuthenticatedAs(guestUser());
    expect(guestUser()->hasVerifiedEmail())->toBeTrue();
});

it('refuses to run without a guest password', function () {
    guestSource();
    config(['guest.password' => null]);

    $this->artisan('guest:refresh')->assertFailed();

    $this->assertDatabaseMissing('users', ['email' => 'guest']);
});

it('resets the guest password and replaces the copy rather than adding to it on a second run', function () {
    $owner = guestSource();
    Holding::factory()->for($owner)->create();
    $this->artisan('guest:refresh');
    guestUser()->update(['password' => 'changed-by-a-visitor']);

    $this->artisan('guest:refresh')->assertSuccessful();

    expect(Hash::check('guest-password', guestUser()->password))->toBeTrue()
        ->and(Holding::query()->onlyOwnedBy(guestUser())->count())->toBe(1);
});

it('scales every amount in the copied fleet', function () {
    ownerFleet(guestSource());

    $this->artisan('guest:refresh');

    $guest = guestUser();
    expect(Profile::query()->onlyOwnedBy($guest)->value('ss_monthly_benefit'))->toEqual(3000)
        ->and(Holding::query()->onlyOwnedBy($guest)->where('type', 'brokerage')->first())
        ->balance->toEqual(1500)
        ->monthly_contribution->toEqual(150)
        ->and(Position::query()->whereRelation('holding', 'user_id', $guest->id)->value('value'))->toEqual(600)
        ->and(Flow::query()->onlyOwnedBy($guest)->where('category', 'salary')->value('amount'))->toEqual(7500)
        ->and(Flow::query()->onlyOwnedBy($guest)->where('category', 'food')->value('amount'))->toEqual(34.49)
        ->and(ScenarioFlow::query()->whereRelation('scenario', 'user_id', $guest->id)->first()->overrides)->toEqual(['2032' => 150000])
        ->and(ConversionStrategy::query()->onlyOwnedBy($guest)->first())
        ->conversion_amount->toEqual(15000)
        ->overrides->toEqual(['2030' => ['conversion' => 30000, 'withheld' => null]])
        ->and(Transfer::query()->onlyOwnedBy($guest)->value('amount'))->toEqual(450)
        ->and(Goal::query()->onlyOwnedBy($guest)->first())
        ->target_amount->toEqual(120000)
        ->saved_amount->toEqual(1500);
});

it('replaces every name in the copied fleet and drops institutions, notes and descriptions', function () {
    ownerFleet(guestSource());

    $this->artisan('guest:refresh');

    $guest = guestUser();
    $brokerage = Holding::query()->onlyOwnedBy($guest)->where('type', 'brokerage')->first();
    expect(Holding::query()->onlyOwnedBy($guest)->orderBy('id')->pluck('name')->all())->toBe(['Roth IRA', 'Brokerage account', 'Real estate', 'Mortgage'])
        ->and($brokerage->institution)->toBeNull()
        ->and($brokerage->notes)->toBeNull()
        ->and(Position::query()->where('holding_id', $brokerage->id)->first())
        ->name->toBe('Index fund')
        ->symbol->toBeNull()
        ->and(Flow::query()->onlyOwnedBy($guest)->orderBy('id')->pluck('name')->all())->toBe(['W-2 salary', 'Household expenses', 'Food'])
        ->and(Scenario::query()->onlyOwnedBy($guest)->first())
        ->name->toBe('Scenario 1')
        ->description->toBeNull()
        ->and(ConversionStrategy::query()->onlyOwnedBy($guest)->value('name'))->toBe('Fixed amount each year')
        ->and(Transfer::query()->onlyOwnedBy($guest)->value('name'))->toBe('A fixed amount each month')
        ->and(Goal::query()->onlyOwnedBy($guest)->value('name'))->toBe('Goal 1');
});

it('points every copied reference at the guest\'s copy and leaves the owner\'s fleet as it was', function () {
    $owner = guestSource();
    $fleet = ownerFleet($owner);

    $this->artisan('guest:refresh');

    $guest = guestUser();
    $holdings = Holding::query()->onlyOwnedBy($guest)->get()->keyBy('type');
    $flows = Flow::query()->onlyOwnedBy($guest)->get()->keyBy('category');
    $scenario = Scenario::query()->onlyOwnedBy($guest)->first();
    $strategy = ConversionStrategy::query()->onlyOwnedBy($guest)->first();
    expect($holdings['brokerage']->parent_id)->toBe($holdings['retirement']->id)
        ->and($holdings['mortgage']->secured_by_id)->toBe($holdings['real_estate']->id)
        ->and($flows['salary']->account_id)->toBe($holdings['brokerage']->id)
        ->and($flows['food']->parent_id)->toBe($flows['household']->id)
        ->and(ScenarioFlow::query()->where('scenario_id', $scenario->id)->value('flow_id'))->toBe($flows['salary']->id)
        ->and(ConversionReportEntry::query()->onlyOwnedBy($guest)->first())
        ->conversion_strategy_id->toBe($strategy->id)
        ->scenario_id->toBe($scenario->id)
        ->and(Transfer::query()->onlyOwnedBy($guest)->first())
        ->from_holding_id->toBe($holdings['brokerage']->id)
        ->to_holding_id->toBe($holdings['mortgage']->id)
        ->and($fleet['inside']->fresh())
        ->name->toBe('Real Bank brokerage')
        ->balance->toEqual(1000)
        ->and(Holding::query()->onlyOwnedBy($owner)->count())->toBe(4);
});

it('copies the Monte Carlo settings without the owner\'s stored results', function () {
    $owner = guestSource();
    MonteCarloRun::factory()->for($owner)->create(['runs' => 500, 'status' => 'done', 'inputs_hash' => 'abc', 'results' => ['median' => 1], 'ran_at' => now()]);

    $this->artisan('guest:refresh');

    expect(MonteCarloRun::query()->onlyOwnedBy(guestUser())->first())
        ->runs->toBe(500)
        ->status->toBe('idle')
        ->inputs_hash->toBeNull()
        ->results->toBeNull()
        ->ran_at->toBeNull();
});

it('links the guest to the owner\'s Wargaming account without its token and copies what was entered by hand', function () {
    $owner = guestSource();
    $account = WotAccount::factory()->for($owner)->create(['account_id' => 1005000001, 'nickname' => 'Owner']);
    WotTankPurchase::query()->create(['wot_account_id' => $account->id, 'tank_id' => 1, 'research_xp' => 4200]);
    $crew = WotTankCrew::query()->create(['wot_account_id' => $account->id, 'tank_id' => 1]);
    WotCrewMember::query()->create(['wot_tank_crew_id' => $crew->id, 'slot' => 0, 'skill_level' => 3]);
    $guide = WotCrewGuide::factory()->create(['wot_account_id' => $account->id, 'name' => 'Heavy']);
    $guide->roles()->create(['role' => 'commander', 'included' => ['sixth_sense'], 'notes' => 'First']);
    WotBookmark::query()->create(['user_id' => $owner->id, 'label' => 'Tomato', 'url' => 'https://tomato.gg', 'position' => 0]);

    $this->artisan('guest:refresh');

    $guestAccount = guestUser()->wotAccount;
    expect($guestAccount)
        ->account_id->toBe(1005000001)
        ->nickname->toBe('Owner')
        ->access_token->toBeNull()
        ->and(WotTankPurchase::query()->where('wot_account_id', $guestAccount->id)->value('research_xp'))->toBe(4200)
        ->and(WotCrewMember::query()->whereRelation('crew', 'wot_account_id', $guestAccount->id)->value('skill_level'))->toBe(3)
        ->and(WotCrewGuide::query()->where('wot_account_id', $guestAccount->id)->first()->roles->first())
        ->role->toBe('commander')
        ->included->toBe(['sixth_sense'])
        ->and(WotBookmark::query()->where('user_id', guestUser()->id)->value('label'))->toBe('Tomato')
        ->and($account->fresh()->access_token)->not->toBeNull();
});

it('leaves the guest unlinked when the owner has no Wargaming account', function () {
    guestSource();

    $this->artisan('guest:refresh')->assertSuccessful();

    expect(guestUser()->wotAccount)->toBeNull();
});
