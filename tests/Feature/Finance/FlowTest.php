<?php

use Illuminate\Support\Carbon;
use App\Models\Finance\Flow;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

it('annualises an amount by its frequency', function (string $frequency, float $annual) {
    $flow = Flow::factory()->make(['amount' => 100, 'frequency' => $frequency]);

    expect($flow->annual_amount)->toBe($annual);
})->with([
    ['weekly', 5200.0],
    ['biweekly', 2600.0],
    ['semimonthly', 2400.0],
    ['monthly', 1200.0],
    ['quarterly', 400.0],
    ['annual', 100.0],
    'a one-time flow has no run rate' => ['once', 0.0],
]);

it('annualises an hourly rate by the hours a week it is worked', function () {
    $flow = Flow::factory()->income('contract')->make(['amount' => 95, 'frequency' => 'hourly', 'hours_per_week' => 10]);

    expect($flow->annual_amount)->toBe(95.0 * 10 * 52);
});

it('compounds the growth rate from this year forward', function () {
    $flow = Flow::factory()->make(['amount' => 1000, 'frequency' => 'monthly', 'annual_growth_rate' => 10]);

    expect($flow->amountInYear(2026))->toBe(12000.0)
        ->and($flow->amountInYear(2028))->toEqualWithDelta(14520, 0.01);
});

it('counts only the months a flow is active in its first and last year', function () {
    $flow = Flow::factory()->make([
        'amount' => 1000,
        'frequency' => 'monthly',
        'starts_on' => '2027-10-01',
        'ends_on' => '2029-03-31',
    ]);

    expect($flow->amountInYear(2026))->toBe(0.0)
        ->and($flow->amountInYear(2027))->toBe(3000.0)
        ->and($flow->amountInYear(2028))->toBe(12000.0)
        ->and($flow->amountInYear(2029))->toBe(3000.0)
        ->and($flow->amountInYear(2030))->toBe(0.0);
});

/**
 * Nobody puts an end date on their salary. Without this the retirement tools
 * would have people drawing a paycheque at ninety.
 */
it('stops earned income at retirement when it has no end date of its own', function () {
    $salary = Flow::factory()->income('salary')->make(['amount' => 5000, 'frequency' => 'monthly']);
    $pension = Flow::factory()->income('pension')->make(['amount' => 5000, 'frequency' => 'monthly']);

    expect($salary->amountInYear(2039, retirementYear: 2040))->toBe(60000.0)
        ->and($salary->amountInYear(2040, retirementYear: 2040))->toBe(0.0)
        ->and($pension->amountInYear(2040, retirementYear: 2040))->toBe(60000.0);
});

it('lands a one-time flow in the year of its date and no other', function () {
    $flow = Flow::factory()->income('contract')->make(['amount' => 3200, 'frequency' => 'once', 'starts_on' => '2027-02-01']);

    expect($flow->amountInYear(2026))->toBe(0.0)
        ->and($flow->amountInYear(2027))->toBe(3200.0);
});

it('plans a monthly figure only for the months a flow covers', function () {
    $flow = Flow::factory()->make(['amount' => 1200, 'frequency' => 'annual', 'starts_on' => '2026-11-15']);

    expect($flow->plannedFor(Carbon::parse('2026-10-01')))->toBe(0.0)
        ->and($flow->plannedFor(Carbon::parse('2026-11-01')))->toBe(100.0);
});

it('plans a one-time flow whole, in its own month', function () {
    $flow = Flow::factory()->make(['amount' => 3200, 'frequency' => 'once', 'starts_on' => '2026-12-10']);

    expect($flow->plannedFor(Carbon::parse('2026-12-01')))->toBe(3200.0)
        ->and($flow->plannedFor(Carbon::parse('2027-01-01')))->toBe(0.0);
});

it('taxes all of an income unless a smaller portion is set, and expenses not at all', function () {
    expect(Flow::factory()->income('social_security')->make()->taxable_share)->toBe(1.0)
        ->and(Flow::factory()->income('social_security')->make(['taxed_portion' => 85])->taxable_share)->toBe(0.85)
        ->and(Flow::factory()->income('salary')->make()->taxable_share)->toBe(1.0)
        ->and(Flow::factory()->income('salary')->make(['taxation' => null])->taxable_share)->toBe(0.0)
        ->and(Flow::factory()->make()->taxable_share)->toBe(0.0);
});
