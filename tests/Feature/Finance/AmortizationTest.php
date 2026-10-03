<?php

use App\Services\Finance\Amortization;

/*
 * The figures here are the textbook ones — a $300,000 loan at 6% over thirty
 * years is $1,798.65 a month on any mortgage calculator — so a failure means
 * the arithmetic moved, not that a fixture did.
 */

it('finds the level payment on a loan', function () {
    expect(round(Amortization::payment(300000, 6, 360), 2))->toBe(1798.65);
});

it('divides the principal evenly when there is no interest', function () {
    expect(Amortization::payment(12000, 0, 24))->toBe(500.0);
});

it('clears a loan on schedule at its level payment', function () {
    $schedule = Amortization::schedule(300000, 6, Amortization::payment(300000, 6, 360));

    expect($schedule['months'])->toBe(360)
        ->and($schedule['paid_off'])->toBeTrue()
        ->and($schedule['total_interest'])->toEqualWithDelta(347514.57, 1.0)
        ->and($schedule['years'])->toHaveCount(30);
});

it('clears a loan sooner, for less interest, with a larger payment', function () {
    $payment = Amortization::payment(300000, 6, 360);
    $standard = Amortization::schedule(300000, 6, $payment);
    $accelerated = Amortization::schedule(300000, 6, $payment + 300);

    expect($accelerated['months'])->toBeLessThan($standard['months'])
        ->and($accelerated['total_interest'])->toBeLessThan($standard['total_interest']);
});

/**
 * $100 a month against $200 of monthly interest goes backwards for ever. That
 * has to come back as an answer, not hang the request.
 */
it('reports a payment that never clears the loan instead of looping', function () {
    $schedule = Amortization::schedule(10000, 24, 100, maxMonths: 120);

    expect($schedule['paid_off'])->toBeFalse()
        ->and($schedule['months'])->toBe(120);
});

it('agrees with its own schedule on the balance part-way through', function () {
    $schedule = Amortization::schedule(300000, 6, Amortization::payment(300000, 6, 360));

    expect(Amortization::balanceAfter(300000, 6, 360, 120))->toEqualWithDelta($schedule['balances'][120], 0.01);
});

it('treats an annual return as an effective yield', function () {
    // 7% a year means 7% bigger a year on, not 7.23% (which monthly
    // compounding of 7/12 would give).
    expect(Amortization::futureValue(10000, 0, 7, 12))->toEqualWithDelta(10700, 0.01);
});

it('finds the deposit that reaches a target, and reaches it', function () {
    $deposit = Amortization::depositFor(50000, 5000, 4, 60);

    expect(Amortization::futureValue(5000, $deposit, 4, 60))->toEqualWithDelta(50000, 0.01);
});

it('asks for no deposit when the money already there gets there alone', function () {
    expect(Amortization::depositFor(10000, 9900, 7, 60))->toBe(0.0);
});
