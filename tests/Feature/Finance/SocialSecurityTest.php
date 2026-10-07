<?php

use App\Models\Finance\Flow;
use App\Models\Finance\Profile;
use App\Models\Finance\SocialSecurityStrategy;
use App\Models\User;
use App\Services\Finance\SocialSecurityBoard;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/**
 * Someone born in June 1960 — full retirement age 67, reached in June 2027 —
 * with a full benefit of $2,000 a month, planning to 90 (2050).
 *
 * @param  array<string, mixed>  $profile
 */
function claimant(array $profile = []): User
{
    $user = User::factory()->create();

    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1960-06-15', 'life_expectancy' => 90, 'ss_monthly_benefit' => 2000, ...$profile]);

    return $user;
}

/**
 * The strategy as the Social Security tab returns it.
 *
 * @return array<string, mixed>
 */
function claimed(User $user, SocialSecurityStrategy $strategy): array
{
    return app(SocialSecurityBoard::class)->for($user)['strategies']->firstWhere('id', $strategy->id);
}

// The rules

it('reads full retirement age off the birth year', function (int $birthYear, int $months) {
    expect(app(SocialSecurityBoard::class)->fullRetirementAge($birthYear))->toBe($months);
})->with([
    [1937, 65 * 12],
    [1943, 66 * 12],
    [1954, 66 * 12],
    [1957, 66 * 12 + 6],
    [1960, 67 * 12],
    [1985, 67 * 12],
]);

it('reduces a benefit claimed early and credits one claimed late, up to 70', function (int $age, float $monthly) {
    // Born in 1970, so every one of these ages is still ahead.
    $user = claimant(['birth_date' => '1970-06-15']);
    $strategy = SocialSecurityStrategy::factory()->claimingAt($age)->create(['user_id' => $user->id]);

    expect(claimed($user, $strategy)['claims']['self']['own'])->toEqualWithDelta($monthly, 0.01);
})->with([
    // 60 months early: 36 at 5/9 of 1% and 24 at 5/12 of 1% is 30% off.
    'at 62' => [62, 1400.0],
    // 24 months early: 13⅓% off.
    'at 65' => [65, 1733.33],
    'at full retirement age' => [67, 2000.0],
    // 36 months late at 2/3 of 1%: 24% more.
    'at 70' => [70, 2480.0],
]);

it('pays from the month of the claim, and adds up the plan in today\'s dollars', function () {
    $user = claimant();
    $strategy = SocialSecurityStrategy::factory()->claimingAt(67)->create(['user_id' => $user->id]);

    $result = claimed($user, $strategy);

    expect($result['claims']['self']['starts_on'])->toBe('2027-06-01')
        ->and($result['rows'][0])->toMatchArray(['year' => 2026, 'self' => 0.0])
        // June to December.
        ->and($result['rows'][1])->toMatchArray(['year' => 2027, 'age' => 67, 'self' => 14000.0])
        ->and($result['rows'][2])->toMatchArray(['year' => 2028, 'self' => 24000.0, 'cumulative' => 38000.0])
        // Seven months of 2027, then every year to 2050.
        ->and($result['summary']['lifetime'])->toBe(14000.0 + 24000.0 * 23)
        ->and($result['summary']['first_year'])->toBe(2027);
});

it('lets a benefit drift against prices when its cost-of-living rise is not inflation', function () {
    $user = claimant(['inflation_rate' => 0]);
    $strategy = SocialSecurityStrategy::factory()->claimingAt(67)->create(['user_id' => $user->id, 'cola_rate' => 10]);

    // 2028 is two years of 10% on from today.
    expect(claimed($user, $strategy)['rows'][2]['self'])->toBe(29040.0);
});

it('tops a spouse up to half the other\'s full benefit once both have claimed, reduced when it starts early', function (int $age, float $own, float $topUp) {
    $user = claimant(['birth_date' => '1970-06-15', 'spouse_birth_date' => '1970-06-15', 'spouse_ss_monthly_benefit' => 500]);
    $strategy = SocialSecurityStrategy::factory()->claimingAt($age)->create(['user_id' => $user->id]);

    $spouse = claimed($user, $strategy)['claims']['spouse'];

    expect($spouse['own'])->toEqualWithDelta($own, 0.01)
        ->and($spouse['top_up'])->toEqualWithDelta($topUp, 0.01);
})->with([
    // Half of 2,000 is 1,000: 500 more than their own.
    'both at full retirement age' => [67, 500.0, 500.0],
    // Own benefit 30% off; the top-up 36 months at 25/36 of 1% and 24 at 5/12, 35% off.
    'both at 62' => [62, 350.0, 325.0],
    // Waiting earns the top-up nothing.
    'both at 70' => [70, 620.0, 500.0],
]);

it('leaves a survivor the larger of the two benefits', function () {
    // The owner is planned to 80 (2040); the spouse to 90 (2050).
    $user = claimant(['life_expectancy' => 80, 'spouse_birth_date' => '1960-06-15', 'spouse_ss_monthly_benefit' => 500, 'spouse_life_expectancy' => 90]);
    $strategy = SocialSecurityStrategy::factory()->claimingAt(67)->create(['user_id' => $user->id]);

    $rows = collect(claimed($user, $strategy)['rows'])->keyBy('year');

    expect($rows[2040])->toMatchArray(['self' => 24000.0, 'spouse' => 12000.0])
        ->and($rows[2041])->toMatchArray(['self' => 0.0, 'spouse' => 24000.0])
        ->and($rows->keys()->last())->toBe(2050);
});

it('works out how much of a benefit is taxable from the household\'s other income', function (float $other, float $taxable) {
    expect(app(SocialSecurityBoard::class)->taxable(20000, $other, [25000, 34000]))->toBe($taxable);
})->with([
    'under the first threshold' => [10000.0, 0.0],
    // Provisional income 30,000: half of the 5,000 over 25,000.
    'between the two' => [20000.0, 2500.0],
    // Provisional income 40,000: 85% of the 6,000 over 34,000, plus half the 9,000 between.
    'over the second' => [30000.0, 9600.0],
    'never more than 85% of it' => [500000.0, 17000.0],
]);

it('names the age a later claim overtakes the earliest one', function () {
    $user = claimant(['inflation_rate' => 0]);
    $early = SocialSecurityStrategy::factory()->claimingAt(67)->create(['user_id' => $user->id, 'name' => 'At 67']);
    $late = SocialSecurityStrategy::factory()->claimingAt(70)->create(['user_id' => $user->id, 'name' => 'At 70']);

    // By the end of 2042 waiting has paid 29,760 × 12.58 years to the other's 24,000 × 15.58.
    expect(claimed($user, $late)['breakeven'])->toBe(['age' => 82, 'against' => 'At 67'])
        ->and(claimed($user, $early)['breakeven'])->toBe(['age' => null, 'against' => null]);
});

it('claims now for someone already past the age the strategy names', function () {
    // 66 and four months in October 2026: eight months short of 67, at 5/9 of 1% each.
    $user = claimant();
    $strategy = SocialSecurityStrategy::factory()->claimingAt(62)->create(['user_id' => $user->id]);

    $claim = claimed($user, $strategy)['claims']['self'];

    expect($claim['starts_on'])->toBe('2026-10-01')
        ->and($claim['own'])->toEqualWithDelta(1911.11, 0.01);
});

// The page

it('shows the tab with each person, their full retirement age and the strategies', function () {
    $user = claimant(['spouse_birth_date' => '1957-02-01', 'spouse_ss_monthly_benefit' => 900]);
    SocialSecurityStrategy::factory()->claimingAt(67)->create(['user_id' => $user->id, 'name' => 'At 67']);

    $this->actingAs($user)->get(route('finance.retirement', 'social-security'))
        ->assertInertia(fn ($page) => $page
            ->component('SocialSecurity')
            ->where('has_spouse', true)
            ->where('has_benefit', true)
            ->where('people.self.full_retirement_age', ['years' => 67, 'months' => 0])
            ->where('people.spouse.full_retirement_age', ['years' => 66, 'months' => 6])
            ->where('benefits.spouse_birth_date', '1957-02-01')
            ->has('strategies', 1)
            ->where('strategies.0.name', 'At 67')
            ->has('strategies.0.summary.present_value'));
});

// Managing

it('saves the household\'s benefits on the profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('finance.retirement.social-security.benefits'), ['ss_monthly_benefit' => 2400, 'spouse_birth_date' => '1962-04-09', 'spouse_ss_monthly_benefit' => 1100, 'spouse_life_expectancy' => 94])->assertSessionHasNoErrors();

    $profile = Profile::for($user);
    expect($profile->ss_monthly_benefit)->toBe(2400.0)
        ->and($profile->spouse_birth_date->toDateString())->toBe('1962-04-09')
        ->and($profile->spouse_ss_monthly_benefit)->toBe(1100.0)
        ->and($profile->spouse_life_expectancy)->toBe(94);
});

it('adds, changes and removes a strategy', function () {
    $user = claimant();
    $payload = ['name' => 'Early', 'claim_age' => 62, 'claim_months' => 6, 'spouse_claim_age' => null, 'spouse_claim_months' => 0, 'cola_rate' => null, 'discount_rate' => 5];

    $this->actingAs($user)->post(route('finance.retirement.social-security.store'), $payload)->assertSessionHasNoErrors();

    $strategy = SocialSecurityStrategy::query()->sole();
    expect($strategy->only(['user_id', 'name', 'claim_age', 'claim_months', 'discount_rate']))->toBe(['user_id' => $user->id, 'name' => 'Early', 'claim_age' => 62, 'claim_months' => 6, 'discount_rate' => 5.0]);

    $this->actingAs($user)->patch(route('finance.retirement.social-security.update', $strategy), [...$payload, 'claim_age' => 68])->assertSessionHasNoErrors();

    expect($strategy->fresh()->claim_age)->toBe(68);

    $this->actingAs($user)->delete(route('finance.retirement.social-security.destroy', $strategy))->assertRedirect();

    $this->assertModelMissing($strategy);
});

it('refuses an age nobody can claim at', function (int $age) {
    $this->actingAs(claimant())
        ->post(route('finance.retirement.social-security.store'), ['name' => 'Odd', 'claim_age' => $age, 'claim_months' => 0, 'spouse_claim_months' => 0])
        ->assertSessionHasErrors('claim_age');

    expect(SocialSecurityStrategy::query()->count())->toBe(0);
})->with([61, 71]);

it('starts with the three ages worth comparing, each person at their own full retirement age', function () {
    $user = claimant(['spouse_birth_date' => '1957-02-01']);

    $this->actingAs($user)->post(route('finance.retirement.social-security.starters'))->assertRedirect();

    expect(SocialSecurityStrategy::query()->orderBy('id')->get()->map->only(['claim_age', 'claim_months', 'spouse_claim_age', 'spouse_claim_months'])->all())->toBe([
        ['claim_age' => 62, 'claim_months' => 0, 'spouse_claim_age' => 62, 'spouse_claim_months' => 0],
        ['claim_age' => 67, 'claim_months' => 0, 'spouse_claim_age' => 66, 'spouse_claim_months' => 6],
        ['claim_age' => 70, 'claim_months' => 0, 'spouse_claim_age' => 70, 'spouse_claim_months' => 0],
    ]);
});

it('will not change, apply or remove another user\'s strategy', function (string $method, string $route) {
    $strategy = SocialSecurityStrategy::factory()->create(['name' => 'Theirs']);

    $this->actingAs(claimant())->{$method}(route($route, $strategy), ['name' => 'Mine', 'claim_age' => 65, 'claim_months' => 0, 'spouse_claim_months' => 0])->assertNotFound();

    expect($strategy->fresh()->name)->toBe('Theirs')
        ->and(Flow::query()->count())->toBe(0);
})->with([
    ['patch', 'finance.retirement.social-security.update'],
    ['post', 'finance.retirement.social-security.apply'],
    ['delete', 'finance.retirement.social-security.destroy'],
]);

// Applying

it('writes a strategy into the income, and replaces what an earlier one wrote', function () {
    $user = claimant(['inflation_rate' => 2.5]);
    $own = Flow::factory()->income('social_security')->create(['user_id' => $user->id, 'name' => 'My own estimate', 'amount' => 1]);
    $early = SocialSecurityStrategy::factory()->claimingAt(67)->create(['user_id' => $user->id]);
    $late = SocialSecurityStrategy::factory()->claimingAt(70)->create(['user_id' => $user->id, 'cola_rate' => 3]);

    $this->actingAs($user)->post(route('finance.retirement.social-security.apply', $early))->assertSessionHas('success');
    $this->actingAs($user)->post(route('finance.retirement.social-security.apply', $late))->assertSessionHas('success');

    $written = Flow::query()->where('name', 'Social Security')->sole();
    expect($written->only(['direction', 'category', 'amount', 'frequency', 'annual_growth_rate', 'taxation']))
        ->toBe(['direction' => 'income', 'category' => 'social_security', 'amount' => 2480.0, 'frequency' => 'monthly', 'annual_growth_rate' => 3.0, 'taxation' => 'income_only'])
        ->and($written->starts_on->toDateString())->toBe('2030-06-01')
        ->and($written->ends_on->toDateString())->toBe('2050-12-31')
        // A Social Security income under another name is the user's own.
        ->and($own->fresh()->amount)->toBe(1.0);
});

it('says so when there is no benefit to write', function () {
    $user = claimant(['ss_monthly_benefit' => null]);
    $strategy = SocialSecurityStrategy::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post(route('finance.retirement.social-security.apply', $strategy))
        ->assertSessionHas('error', 'There is no benefit to write yet. Enter a monthly benefit first.');

    expect(Flow::query()->count())->toBe(0);
});

/**
 * A spousal top-up starts once both have claimed. Written into the spouse's
 * own benefit it would be paid from the day the spouse claimed — here, eight
 * years too soon.
 */
it('writes a spousal top-up as an income of its own, from the month it is first paid', function () {
    $user = claimant(['birth_date' => '1970-06-15', 'spouse_birth_date' => '1970-06-15', 'spouse_ss_monthly_benefit' => 500]);
    $strategy = SocialSecurityStrategy::factory()->claimingAt(70)->create(['user_id' => $user->id, 'spouse_claim_age' => 62]);

    $this->actingAs($user)->post(route('finance.retirement.social-security.apply', $strategy))->assertSessionHas('success');

    $written = Flow::query()->orderBy('starts_on')->get()->map(fn (Flow $flow): array => [$flow->name, $flow->amount, $flow->starts_on->toDateString()])->all();

    expect($written)->toBe([
        ['Social Security (spouse)', 350.0, '2032-06-01'],
        ['Social Security', 2480.0, '2040-06-01'],
        ['Social Security (spouse, spousal top-up)', 500.0, '2040-06-01'],
    ]);
});
