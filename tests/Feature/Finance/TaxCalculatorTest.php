<?php

use App\Models\Finance\Profile;
use App\Services\Finance\TaxCalculator;

/*
 * Pinned to the 2026 tables in config/finance.php. When those are updated for
 * a new tax year these figures move with them, deliberately: this is the test
 * that says the table was typed in right.
 */

it('taxes nothing inside the standard deduction', function () {
    expect((new TaxCalculator)->tax(16100, 'single'))->toBe(0.0);
});

it('taxes each slice of income at its own bracket rate', function () {
    // $66,500 gross, single: $50,400 taxable, exactly the top of the 12%
    // bracket. 10% of 12,400 plus 12% of the next 38,000.
    expect((new TaxCalculator)->tax(66500, 'single'))->toEqualWithDelta(1240 + 4560, 0.01);
});

it('uses the wider brackets for a joint return', function () {
    $tax = new TaxCalculator;

    // $133,000 gross, joint: $100,800 taxable — the top of the joint 12% bracket.
    expect($tax->tax(133000, 'married_joint'))->toEqualWithDelta(2480 + 9120, 0.01)
        ->and($tax->tax(133000, 'married_joint'))->toBeLessThan($tax->tax(133000, 'single'));
});

it('reaches the open top bracket', function () {
    $tax = new TaxCalculator;

    expect($tax->tax(1_016_100, 'single') - $tax->tax(916_100, 'single'))->toEqualWithDelta(37000, 0.01);
});

it('reports the rate on the next dollar', function (float $gross, float $rate) {
    expect((new TaxCalculator)->marginalRate($gross, 'single'))->toBe($rate);
})->with([
    'inside the deduction' => [10000, 0.0],
    'first bracket' => [20000, 10.0],
    'top of the 12% bracket is already 22%' => [66500, 22.0],
    'top bracket' => [900000, 37.0],
]);

it('treats the standard deduction as the top of a 0% bracket', function () {
    expect((new TaxCalculator)->grossCeiling(0.0, 'single'))->toBe(16100.0);
});

it('gives the gross income at which a bracket tops out', function () {
    expect((new TaxCalculator)->grossCeiling(12.0, 'single'))->toBe(66500.0)
        ->and((new TaxCalculator)->grossCeiling(37.0, 'single'))->toBeNull();
});

/**
 * A projection that held the tables still would push every future year into
 * higher brackets on inflation alone. Indexing has to leave real tax unchanged.
 */
it('scales the whole table with the index, so real tax is unchanged', function () {
    $tax = new TaxCalculator;

    expect($tax->tax(66500 * 1.5, 'single', 1.5))->toEqualWithDelta($tax->tax(66500, 'single') * 1.5, 0.01);
});

// A household's incomes, by how each is taxed

/**
 * $100,000, single: $83,900 over the deduction is $13,170 of income tax
 * whichever way it was earned. What differs is the payroll tax on top.
 */
it('adds payroll tax by how the income was earned', function (string $taxation, float $payroll) {
    $tax = (new TaxCalculator)->flowTaxFor(new Profile)([$taxation => 100000]);

    expect($tax['income'])->toEqualWithDelta(13170, 0.01)
        ->and($tax['payroll'])->toEqualWithDelta($payroll, 0.01)
        ->and($tax['total'])->toEqualWithDelta(13170 + $payroll, 0.01);
})->with([
    'a W-2 wage pays half the self-employment rate' => ['w2', 7650],
    'the self-employed pay all of it' => ['self_employed', 15300],
    'income tax only pays none' => ['income_only', 0],
]);

it('stacks capital gains on top of ordinary income', function () {
    $taxOn = (new TaxCalculator)->flowTaxFor(new Profile);

    // $66,500 of ordinary income is $50,400 taxable, already past the
    // $49,450 the 0% rate runs to, so every dollar of gain is at 15%.
    expect($taxOn(['income_only' => 66500, 'capital_gains' => 10000])['capital_gains'])->toEqualWithDelta(1500, 0.01)
        // Gains alone: the deduction comes off first, and the $23,900 left
        // sits inside the 0% rate.
        ->and($taxOn(['capital_gains' => 40000])['total'])->toBe(0.0)
        // $70,000 of gains: $53,900 taxable, $4,450 of it over the line.
        ->and($taxOn(['capital_gains' => 70000])['capital_gains'])->toEqualWithDelta(667.5, 0.01);
});

it('leaves out income with no treatment it knows', function () {
    expect((new TaxCalculator)->flowTaxFor(new Profile)(['gift' => 500000])['total'])->toBe(0.0);
});

it('uses the figures a profile has set in place of the built-in ones', function () {
    $profile = new Profile([
        'standard_deduction' => 30000,
        'se_tax_rate' => 10,
        'ltcg_brackets' => [['rate' => 5, 'up_to' => null]],
    ]);
    $tax = (new TaxCalculator)->flowTaxFor($profile)(['self_employed' => 42400, 'capital_gains' => 1000]);

    // $12,400 over a $30,000 deduction is exactly the 10% bracket.
    expect($tax['income'])->toEqualWithDelta(1240, 0.01)
        ->and($tax['payroll'])->toEqualWithDelta(4240, 0.01)
        ->and($tax['capital_gains'])->toEqualWithDelta(50, 0.01);
});

it('carries a profile\'s own deduction into the bracket questions asked by filing status', function () {
    $tax = (new TaxCalculator)->forProfile(new Profile(['standard_deduction' => 30000]));

    expect($tax->deduction('single'))->toBe(30000.0)
        ->and($tax->tax(42400, 'single'))->toEqualWithDelta(1240, 0.01)
        ->and($tax->grossCeiling(12.0, 'single'))->toBe(80400.0)
        ->and((new TaxCalculator)->forProfile(new Profile)->deduction('single'))->toBe(16100.0);
});
