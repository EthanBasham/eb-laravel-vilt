<?php

use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\User;
use App\Services\Finance\ConversionBoard;
use App\Services\Finance\TaxCalculator;

/**
 * @return array<string, mixed>
 */
function profilePayload(array $overrides = []): array
{
    return [
        'birth_date' => '1970-01-01',
        'filing_status' => 'single',
        'retirement_age' => 65,
        'life_expectancy' => 92,
        'inflation_rate' => 2.5,
        'state' => null,
        'state_deduction' => 0,
        'state_brackets' => [],
        'local_name' => null,
        'local_deduction' => 0,
        'local_brackets' => [],
        ...$overrides,
    ];
}

// Presets

/**
 * The presets only fill the form in, so a wrong figure here is a wrong figure
 * offered to everyone who picks the state. Pinned to the published tables.
 */
it('offers Tennessee with no income tax and New York with its brackets', function () {
    $this->actingAs(User::factory()->create())->get(route('finance.settings'))
        ->assertInertia(fn ($page) => $page
            ->where('presets.TN.brackets.single', [])
            ->where('presets.NY.deduction.single', 8000)
            ->where('presets.NY.brackets.single.0', [3.9, 8500])
            ->where('presets.NY.brackets.single.8', [10.9, null])
            ->where('presets.NY.brackets.married_joint.3', [5.4, 161550])
            ->where('presets.NY.localities.nyc.brackets.single.3', [3.876, null]));
});

// Saving

it('saves a profile\'s own deduction, capital gains brackets and self-employment rate', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('finance.settings.update'), profilePayload([
        'standard_deduction' => 20000,
        'se_tax_rate' => 14.2,
        'ltcg_brackets' => [['rate' => 0, 'up_to' => 60000], ['rate' => 18, 'up_to' => null]],
    ]))->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('finance.settings'))
        ->assertInertia(fn ($page) => $page
            ->where('profile.standard_deduction', 20000)
            ->where('profile.se_tax_rate', 14.2)
            ->where('tax.deduction', 20000)
            ->where('tax.fica_rate', 7.1)
            ->where('tax.built_in_deduction', 16100)
            ->where('tax.additional_deduction', 2050)
            ->where('tax.capital_gains_brackets', [[0, 60000], [18, null]])
            ->where('tax.built_in_capital_gains_brackets.0', [0, 49450]));
});

it('falls back to the built-in deduction and capital gains brackets for the filing status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('finance.settings.update'), profilePayload(['filing_status' => 'married_joint', 'standard_deduction' => null, 'ltcg_brackets' => null]))
        ->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('finance.settings'))
        ->assertInertia(fn ($page) => $page
            ->where('profile.standard_deduction', null)
            ->where('profile.se_tax_rate', 15.3)
            ->where('tax.deduction', 32200)
            ->where('tax.capital_gains_brackets', [[0, 98900], [15, 613700], [20, null]]));
});

it('refuses capital gains brackets that are out of order', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('finance.settings.update'), profilePayload(['ltcg_brackets' => [['rate' => 0, 'up_to' => 60000], ['rate' => 15, 'up_to' => 50000], ['rate' => 20, 'up_to' => null]]]))
        ->assertSessionHasErrors('ltcg_brackets.1.up_to');
});

it('saves the state and local brackets as they were left in the form', function () {
    $user = User::factory()->create();

    // Not New York's own figures: the preset was edited before saving, and
    // the edit is what has to stick.
    $this->actingAs($user)->put(route('finance.settings.update'), profilePayload([
        'state' => 'NY',
        'state_deduction' => 9000,
        'state_brackets' => [['rate' => 4, 'up_to' => 10000], ['rate' => 6, 'up_to' => null]],
        'local_name' => 'New York City',
        'local_deduction' => 8000,
        'local_brackets' => [['rate' => 3.5, 'up_to' => null]],
    ]))->assertSessionHasNoErrors();

    $this->actingAs($user)->get(route('finance.settings'))
        ->assertInertia(fn ($page) => $page
            ->where('profile.state', 'NY')
            ->where('profile.state_label', 'New York')
            ->where('profile.state_deduction', 9000)
            ->where('profile.state_brackets', [['rate' => 4, 'up_to' => 10000], ['rate' => 6, 'up_to' => null]])
            ->where('profile.local_name', 'New York City')
            ->where('profile.local_brackets.0.rate', 3.5)
            ->where('profile.has_state_tax', true));
});

it('accepts a state with no brackets at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('finance.settings.update'), profilePayload(['state' => 'TN']))->assertSessionHasNoErrors();

    expect(Profile::for($user))->state->toBe('TN')->has_state_tax->toBeFalse();
});

it('refuses brackets the calculator could not walk', function (array $brackets, string $field) {
    $this->actingAs(User::factory()->create())
        ->put(route('finance.settings.update'), profilePayload(['state' => 'other', 'state_brackets' => $brackets]))
        ->assertSessionHasErrors($field);
})->with([
    'a rate over 100' => [[['rate' => 120, 'up_to' => null]], 'state_brackets.0.rate'],
    'no rate' => [[['rate' => null, 'up_to' => 5000]], 'state_brackets.0.rate'],
    'an open-ended bracket that is not the last' => [[['rate' => 4, 'up_to' => null], ['rate' => 5, 'up_to' => 9000]], 'state_brackets.0.up_to'],
    'a bracket ending below the one before' => [[['rate' => 4, 'up_to' => 9000], ['rate' => 5, 'up_to' => 5000]], 'state_brackets.1.up_to'],
]);

it('refuses a state it has no preset for, other than "other"', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('finance.settings.update'), profilePayload(['state' => 'ZZ']))
        ->assertSessionHasErrors('state');
});

// Arithmetic

it('taxes income under state and local brackets, each with its own deduction', function () {
    $profile = new Profile([
        'filing_status' => 'single',
        'state_deduction' => 8000,
        'state_brackets' => [['rate' => 4, 'up_to' => 10000], ['rate' => 6, 'up_to' => null]],
        'local_deduction' => 0,
        'local_brackets' => [['rate' => 1, 'up_to' => null]],
    ]);

    $tax = new TaxCalculator;

    // State: 30,000 taxable is 4% of 10,000 plus 6% of 20,000. Local: 1% of 38,000.
    expect($tax->stateAndLocalTax(38000, $profile))->toEqualWithDelta(400 + 1200 + 380, 0.01)
        ->and($tax->totalTax(38000, $profile))->toEqualWithDelta($tax->tax(38000, 'single') + 1980, 0.01);
});

it('adds nothing for a profile with no state or local brackets', function () {
    expect((new TaxCalculator)->stateAndLocalTax(250000, new Profile(['filing_status' => 'single'])))->toBe(0.0);
});

/**
 * The strategizer's question — is tax cheaper now or later — has a different
 * answer in a state that taxes the conversion too.
 */
it('counts state tax in the retirement strategizer', function () {
    test()->travelTo('2026-10-01 09:00:00');

    $user = User::factory()->create();
    $profile = Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1958-03-01', 'retirement_age' => 65, 'life_expectancy' => 80, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 1_000_000, 'annual_rate' => 0]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => 100_000, 'annual_rate' => 0]);

    // Withheld from the conversion: there is no income to pay it from.
    ConversionStrategy::factory()->ofKind('fill_bracket', ['growth_rate' => 0, 'tax_payment' => 'conversion'])->create(['user_id' => $user->id]);

    $filled = fn (): array => app(ConversionBoard::class)->for($user)['strategies'][0];

    $without = $filled();

    $profile->update(['state' => 'other', 'state_brackets' => [['rate' => 5, 'up_to' => null]]]);

    $with = $filled();

    // With no income, the conversion at 68 fills the federal standard
    // deduction: $18,150 at 65 and over, free of federal tax. This state has
    // no deduction of its own, so a flat 5% on it is $908 that year.
    expect($without['rows'][0]['conversion'])->toEqual(18150)
        ->and($without['rows'][0]['tax'])->toEqual(0)
        ->and($with['rows'][0]['conversion'])->toEqual(18150)
        ->and($with['rows'][0]['tax'] - $without['rows'][0]['tax'])->toEqual(908)
        ->and($with['summary']['lifetime_tax'] - $without['summary']['lifetime_tax'])->toBeGreaterThan(908);
});
