<?php

use App\Models\Finance\ConversionReportEntry;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;
use App\Models\Finance\MonteCarloRun;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\ScenarioFlow;
use App\Models\User;
use App\Services\Finance\ConversionBoard;
use App\Services\Finance\ConversionMonteCarlo;
use App\Services\Finance\TaxCalculator;

beforeEach(fn () => test()->travelTo('2026-10-01 09:00:00'));

/**
 * A single retiree of 68 who plans to 80, with $1,000,000 traditional and a
 * brokerage account. Past 65, so the deduction is $16,100 plus $2,050. No inflation and no growth, so a figure in the plan is a
 * figure that can be worked out by hand.
 *
 * `$spare` is untaxed income a year with no expense against it: room in each
 * year to pay a conversion's tax from (which is where tax paid "from outside"
 * comes from) without moving the year's taxable income at all.
 */
function conversionRetiree(float $brokerage = 2_000_000, float $spare = 1_000_000): User
{
    $user = User::factory()->create();

    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1958-03-01', 'retirement_age' => 65, 'life_expectancy' => 80, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 1_000_000, 'annual_rate' => 0]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => $brokerage, 'annual_rate' => 0]);

    if ($spare > 0) {
        Flow::factory()->income('other_income')->create(['user_id' => $user->id, 'name' => 'Spare', 'amount' => $spare, 'frequency' => 'annual', 'taxation' => null]);
    }

    return $user;
}

/**
 * A strategy's column of the report as the page receives it: its settings,
 * rows and summary, run on the projection given or on the flows as entered.
 *
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function runStrategy(User $user, string $kind, array $settings = [], ?Scenario $on = null): array
{
    $strategy = ConversionStrategy::factory()->reported($on)->ofKind($kind, ['growth_rate' => 0, ...$settings])->create(['user_id' => $user->id]);

    return collect(app(ConversionBoard::class)->for($user)['report'])->firstWhere('strategy_id', $strategy->id);
}

/** The row for an age: the plan opens at 68. */
function atAge(array $strategy, int $age): array
{
    return collect($strategy['rows'])->firstWhere('age', $age);
}

// The four kinds of strategy

it('converts nothing and takes the RMDs as they come', function () {
    $plan = runStrategy(conversionRetiree(), 'none');

    // Born before 1960: RMDs from 73, on the IRS divisor of 26.5.
    expect($plan['summary']['converted'])->toEqual(0)
        ->and(atAge($plan, 72)['rmd'])->toEqual(0)
        ->and(atAge($plan, 73)['rmd'])->toEqual(round(1_000_000 / 26.5))
        ->and($plan['rows'])->toHaveCount(13);
});

it('converts the whole balance in the one year', function () {
    $plan = runStrategy(conversionRetiree(), 'lump', ['convert_from_age' => 70]);

    expect(atAge($plan, 69)['conversion'])->toEqual(0)
        ->and(atAge($plan, 70)['conversion'])->toEqual(1_000_000)
        ->and(atAge($plan, 70)['traditional'])->toEqual(0)
        ->and(atAge($plan, 70)['roth'])->toEqual(1_000_000)
        ->and($plan['summary']['converted'])->toEqual(1_000_000)
        // Nothing left to distribute.
        ->and($plan['summary']['total_rmd'])->toEqual(0);
});

it('converts at once when the year named has already passed', function () {
    $plan = runStrategy(conversionRetiree(), 'lump', ['convert_from_age' => 60]);

    expect(atAge($plan, 68)['conversion'])->toEqual(1_000_000)
        ->and($plan['assumptions']['convert_from_age'])->toBe(68);
});

it('empties the balance in equal parts across the window', function () {
    // The usual window is 65 through 72; at 68 that leaves five years.
    $plan = runStrategy(conversionRetiree(), 'even');

    expect($plan['assumptions'])->toMatchArray(['convert_from_age' => 68, 'convert_until_age' => 72])
        ->and(collect($plan['rows'])->whereBetween('age', [68, 72])->pluck('conversion')->all())->toEqual([200_000, 200_000, 200_000, 200_000, 200_000])
        ->and(atAge($plan, 73)['conversion'])->toEqual(0)
        ->and($plan['summary']['ending_traditional'])->toEqual(0);
});

it('converts the same amount each year, then whatever is left', function () {
    // The usual window is 65 through 72; at 68 that leaves five years, and
    // $1,000,000 runs out in the fourth.
    $plan = runStrategy(conversionRetiree(), 'fixed', ['conversion_amount' => 300_000]);

    expect($plan['assumptions'])->toMatchArray(['convert_from_age' => 68, 'convert_until_age' => 72])
        ->and(collect($plan['rows'])->whereBetween('age', [68, 72])->pluck('conversion')->all())->toEqual([300_000, 300_000, 300_000, 100_000, 0])
        ->and($plan['summary']['converted'])->toEqual(1_000_000)
        ->and($plan['summary']['ending_traditional'])->toEqual(0);
});

it('holds a fixed amount in today\'s dollars, rising with the strategy\'s inflation', function () {
    $plan = runStrategy(conversionRetiree(), 'fixed', ['conversion_amount' => 300_000, 'inflation_rate' => 5]);

    expect(collect($plan['rows'])->whereBetween('age', [68, 70])->pluck('conversion')->all())->toEqual([300_000, 315_000, 330_750])
        ->and(atAge($plan, 71)['conversion'])->toBeLessThan(300_000)
        ->and($plan['summary']['ending_traditional'])->toEqual(0);
});

it('stops a fixed amount at the end of its window, whatever is left', function () {
    $plan = runStrategy(conversionRetiree(), 'fixed', ['conversion_amount' => 100_000, 'convert_from_age' => 68, 'convert_until_age' => 70]);

    expect($plan['summary']['converted'])->toEqual(300_000)
        ->and(atAge($plan, 71)['conversion'])->toEqual(0);
});

it('fills the named bracket each year, leaving room for the RMD', function () {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12]);

    // $68,550 is where the 12% bracket tops out for a single filer of 65 or
    // more with no other income, and that is what the year's income comes to:
    // $50,400 of it taxable.
    expect(atAge($plan, 68)['conversion'])->toEqual(68_550)
        ->and(atAge($plan, 68)['bracket_income'])->toEqual(68_550)
        ->and(atAge($plan, 68)['taxable_income'])->toEqual(50_400)
        ->and(atAge($plan, 68)['taxable_income_before'])->toEqual(0)
        // Filled to the top of the 12% bracket and no further.
        ->and(atAge($plan, 68)['marginal_rate'])->toEqual(12)
        ->and(atAge($plan, 73)['rmd'] + atAge($plan, 73)['conversion'])->toEqual(68_550);
});

/**
 * With nothing outside the accounts to pay it from, the conversion's tax is
 * drawn from traditional, and that draw is income too. Filling the bracket
 * means the two together stop at its top: $68,550, not $68,550 converted
 * plus whatever it took to pay for it.
 */
it('leaves room in the bracket for the withdrawal that pays the conversion\'s tax', function () {
    // All of the tax from outside, but as a share rather than "from spare
    // income", so it is not capped: with no savings and no spare income, it
    // can only come out of traditional.
    $plan = runStrategy(conversionRetiree(brokerage: 0, spare: 0), 'fill_bracket', ['fill_rate' => 12, 'tax_payment' => 'percent', 'tax_outside_amount' => 100]);
    $year = atAge($plan, 68);

    expect($year['withdrawal'])->toBeGreaterThan(0)
        ->and($year['conversion'] + $year['withdrawal'])->toEqual(68_550)
        ->and($year['bracket_income'])->toEqual(68_550)
        ->and($year['marginal_rate'])->toEqual(12)
        // Without the conversion there would have been nothing to pay, and
        // so nothing withdrawn: the dashed line starts from zero.
        ->and($year['taxable_income_before'])->toEqual(0);
});

/**
 * A $100,000 pension against $60,000 of expenses leaves $27,281 once the
 * pension's own $12,719 of tax is paid. Filling the 24% bracket in full would
 * add more tax than that, so the conversion stops where its tax comes to
 * $27,281: $23,850 at 22% to the top of that bracket, and $91,808 more at 24%.
 */
it('converts no more than the year\'s spare income can pay the tax on, when the tax is paid from outside', function () {
    $user = conversionRetiree(spare: 0);
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 100_000, 'frequency' => 'annual']);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);

    $year = atAge(runStrategy($user, 'fill_bracket', ['fill_rate' => 24]), 68);

    expect($year['conversion'])->toEqual(115_658)
        ->and($year['conversion_tax'])->toEqual(27_281)
        ->and($year['withdrawal'])->toEqual(0)
        // Paid entirely from the year's spare income: savings untouched.
        ->and($year['taxable'])->toEqualWithDelta(2_000_000, 1);
});

it('converts nothing from outside in a year with nothing to spare, but can still withhold the tax', function () {
    $user = conversionRetiree(spare: 0);
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 40_000, 'frequency' => 'annual']);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);

    expect(runStrategy($user, 'fill_bracket', ['fill_rate' => 12])['summary']['converted'])->toEqual(0)
        ->and(atAge(runStrategy($user, 'fill_bracket', ['fill_rate' => 12, 'tax_payment' => 'conversion']), 68)['conversion'])->toBeGreaterThan(0);
});

it('pays a working year\'s conversion tax from that year\'s spare income, not from savings', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1970-03-01', 'retirement_age' => 65, 'life_expectancy' => 80, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 1_000_000, 'annual_rate' => 0]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => 500_000, 'annual_rate' => 0]);
    Flow::factory()->income('business')->create(['user_id' => $user->id, 'amount' => 100_000, 'frequency' => 'annual']);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);

    $year = atAge(runStrategy($user, 'fill_bracket', ['fill_rate' => 24]), 56);

    expect($year['conversion'])->toEqual(113_608)
        ->and($year['taxable'])->toEqualWithDelta(500_000, 1);
});

/**
 * At 56, earning $100,000 against $60,000 of expenses, and owing $13,170 of
 * tax on it: the $26,830 left is saved, as a retired year's would be. A year
 * that spends more than it earns draws the difference from savings.
 */
it('saves a working year\'s surplus to taxable savings, and draws a shortfall from them', function (float $expenses, float $taxable) {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1970-03-01', 'retirement_age' => 65, 'life_expectancy' => 80, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 1_000_000, 'annual_rate' => 0]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => 500_000, 'annual_rate' => 0]);
    Flow::factory()->income('business')->create(['user_id' => $user->id, 'amount' => 100_000, 'frequency' => 'annual']);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => $expenses, 'frequency' => 'annual']);

    expect(atAge(runStrategy($user, 'none'), 56)['taxable'])->toEqualWithDelta($taxable, 1);
})->with([
    'a surplus' => [60_000, 526_830],
    'a shortfall' => [150_000, 436_830],
]);

/**
 * The same working year, saving $1,000 a month besides: that comes out of the
 * $26,830 that was spare before the conversion's tax can, leaving $14,830 —
 * $21,800 at 22% and $41,808 more at 24%.
 */
it('takes a working year\'s contributions out of its spare income before paying a conversion\'s tax from it', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1970-03-01', 'retirement_age' => 65, 'life_expectancy' => 80, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 1_000_000, 'annual_rate' => 0]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => 500_000, 'annual_rate' => 0, 'monthly_contribution' => 1000]);
    Flow::factory()->income('business')->create(['user_id' => $user->id, 'amount' => 100_000, 'frequency' => 'annual']);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);

    $year = atAge(runStrategy($user, 'fill_bracket', ['fill_rate' => 24]), 56);

    expect($year['conversion_tax'])->toEqualWithDelta(14_830, 1)
        ->and($year['conversion'])->toEqualWithDelta(63_608, 1)
        ->and($year['taxable'])->toEqualWithDelta(512_000, 1);
});

it('fills whichever bracket the income is already in when none is named', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 40_000, 'frequency' => 'annual']);

    // $40,000 is in the 12% bracket, which has $28,550 of room left.
    expect(atAge(runStrategy($user, 'fill_bracket'), 68)['conversion'])->toEqual(28_550);
});

/**
 * A $100,000 pension, single: the 22% bracket has $23,850 of room, but the
 * first IRMAA line, $109,000, is only $9,000 away — and the conversion stops
 * $100 short of it, since a dollar over the line costs the whole step.
 */
it('stops $100 short of the IRMAA line when that comes before the top of the bracket', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 100_000, 'frequency' => 'annual']);

    $bracketOnly = runStrategy($user, 'fill_bracket');
    $both = runStrategy($user, 'fill_bracket_irmaa');

    expect(atAge($bracketOnly, 68)['conversion'])->toEqual(23_850)
        // The premium at 70 is set by the income at 68.
        ->and(atAge($bracketOnly, 70)['irmaa'])->toBeGreaterThan(0)
        ->and(atAge($both, 68)['conversion'])->toEqual(8_900)
        ->and(atAge($both, 68)['magi'])->toEqual(108_900)
        ->and(atAge($both, 68)['magi_tier'])->toBe(0)
        ->and(atAge($both, 70)['irmaa'])->toEqual(0)
        // It converts less for it, and so leaves more to be distributed.
        ->and($both['summary']['converted'])->toBeLessThan($bracketOnly['summary']['converted']);
});

it('stops at the top of the bracket when that comes before the IRMAA line', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);

    // $60,000 is in the 12% bracket, which tops out at $68,550, long before $109,000.
    expect(atAge(runStrategy($user, 'fill_bracket_irmaa'), 68)['conversion'])->toEqual(8_550)
        // Naming a higher bracket leaves the IRMAA line as the one that binds.
        ->and(atAge(runStrategy($user, 'fill_bracket_irmaa', ['fill_rate' => 24]), 68)['conversion'])->toEqual(48_900);
});

/**
 * The spike this margin is for: with prices rising, a year filled to the
 * dollar sat exactly on the line once deflated two years on, and rounding
 * tipped some of them into the tier above for a few years.
 */
it('holds each year $100 under the line of the tier it is already in, year after year of rising prices', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 100_000, 'frequency' => 'annual', 'annual_growth_rate' => 2.5]);

    $plan = runStrategy($user, 'fill_bracket_irmaa', ['fill_rate' => 24, 'inflation_rate' => 2.5]);
    $rows = collect($plan['rows'])->keyBy('age');

    // How far under a line of its own year an income sits, in today's
    // dollars: the margin is $100 of them, so it rises with prices too.
    // Both figures are rounded to the dollar, so it reads a dollar either way.
    $under = fn (array $row, int $line): float => ($row['irmaa_lines'][$line] - $row['magi']) / 1.025 ** ($row['age'] - 68 + 2);

    // Until the RMDs begin the pension alone is under the first line, and
    // every year is filled to $100 short of it — so no premium two years on
    // is ever raised.
    expect($rows->only([68, 69, 70, 71, 72])->map(fn (array $row): float => $under($row, 0)))->each->toEqualWithDelta(100, 1)
        ->and($rows->only([70, 71, 72, 73, 74])->pluck('irmaa')->unique()->all())->toEqual([0])
        // From 73 the RMD carries income past that line by itself. The
        // conversion then stays $100 under the next one.
        ->and($rows->only([73, 74, 75, 76, 77, 78, 79, 80])->map(fn (array $row): float => $under($row, 1)))->each->toEqualWithDelta(100, 1)
        ->and($rows->max('irmaa_tier'))->toBe(1);
});

it('converts nothing when income is already inside the margin below the line', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 108_950, 'frequency' => 'annual']);

    expect(atAge(runStrategy($user, 'fill_bracket_irmaa'), 68)['conversion'])->toEqual(0);
});

it('ignores the IRMAA tiers before 63, when no premium can be set by the income', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1970-03-01', 'retirement_age' => 50, 'life_expectancy' => 80, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 5_000_000, 'annual_rate' => 0]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => 2_000_000, 'annual_rate' => 0]);
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 100_000, 'frequency' => 'annual']);

    $plan = runStrategy($user, 'fill_bracket_irmaa');

    expect(atAge($plan, 62)['conversion'])->toEqual(21_800)
        ->and(atAge($plan, 63)['conversion'])->toEqual(8_900);
});

it('only converts inside the window it is given', function () {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12, 'convert_from_age' => 70, 'convert_until_age' => 71]);

    expect(collect($plan['rows'])->where('conversion', '>', 0)->pluck('age')->all())->toBe([70, 71]);
});

// Tax, IRMAA and heirs

it('taxes a conversion as ordinary income, in the year it is made', function () {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12, 'convert_from_age' => 68, 'convert_until_age' => 68]);

    // $50,400 over the deduction: 10% of $12,400 and 12% of $38,000.
    expect(atAge($plan, 68)['tax'])->toEqual(5800)
        ->and(atAge($plan, 69)['tax'])->toEqual(0)
        // Paid from the year's spare income, so all of it reaches the Roth
        // and the rest of that income is saved.
        ->and(atAge($plan, 68)['roth'])->toEqual(68_550)
        ->and(atAge($plan, 68)['taxable'])->toEqual(2_000_000 + 1_000_000 - 5800);
});

/**
 * The same one conversion of $68,550, and the same $5,800 of tax on it. What
 * differs is where the $5,800 comes from, and so how much reaches the Roth.
 */
it('takes the conversion\'s tax out of the converted money as the strategy says', function (array $settings, int $fromConversion) {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12, 'convert_from_age' => 68, 'convert_until_age' => 68, ...$settings]);

    expect(atAge($plan, 68)['conversion'])->toEqual(68_550)
        ->and(atAge($plan, 68)['tax'])->toEqual(5800)
        ->and(atAge($plan, 68)['conversion_tax'])->toEqual(5800)
        ->and(atAge($plan, 68)['conversion_tax_withheld'])->toEqual($fromConversion)
        ->and(atAge($plan, 68)['traditional'])->toEqual(1_000_000 - 68_550)
        ->and(atAge($plan, 68)['roth'])->toEqual(68_550 - $fromConversion)
        ->and(atAge($plan, 68)['taxable'])->toEqual(2_000_000 + 1_000_000 - (5800 - $fromConversion))
        ->and($plan['summary'])->toMatchArray(['conversion_tax' => 5800.0, 'conversion_tax_withheld' => (float) $fromConversion]);
})->with([
    'all from outside' => [['tax_payment' => 'outside'], 0],
    'all from the conversion' => [['tax_payment' => 'conversion'], 5800],
    'a quarter from outside' => [['tax_payment' => 'percent', 'tax_outside_amount' => 25], 4350],
    'the first $1,000 from outside' => [['tax_payment' => 'flat', 'tax_outside_amount' => 1000], 4800],
    'a flat amount that covers it all' => [['tax_payment' => 'flat', 'tax_outside_amount' => 9000], 0],
]);

it('counts only the tax the conversion adds, beside the tax the year owed anyway', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 40_000, 'frequency' => 'annual']);

    $row = atAge(runStrategy($user, 'fill_bracket', ['convert_until_age' => 68]), 68);

    // The pension alone owes $2,374; $28,550 converted at 12% adds $3,426.
    expect($row['tax'])->toEqual(5800)
        ->and($row['conversion_tax'])->toEqual(3426)
        ->and(atAge(runStrategy($user, 'none'), 68)['conversion_tax'])->toEqual(0);
});

/**
 * A million dollars of income in 2026 sets the premium for 2028: the top
 * tier, $487.00 more for Part B and $91.00 for Part D each month.
 */
it('charges IRMAA two years after the income that earned it', function () {
    $plan = runStrategy(conversionRetiree(), 'lump', ['convert_from_age' => 68]);

    expect(atAge($plan, 68)['magi'])->toEqual(1_000_000)
        ->and(atAge($plan, 68)['irmaa'])->toEqual(0)
        ->and(atAge($plan, 70)['irmaa_tier'])->toBe(5)
        ->and(atAge($plan, 70)['irmaa'])->toEqual(round((487 + 91) * 12))
        ->and(atAge($plan, 71)['irmaa'])->toEqual(0)
        ->and($plan['summary'])->toMatchArray(['irmaa' => 6936.0, 'irmaa_years' => 1]);
});

/**
 * The plan has no years behind it to look back on, so its first two take the
 * income it opens with — the RMD included. $3,000,000 at 76 is an RMD of
 * $126,582, over the first line by itself.
 */
it('counts the RMD in the income the plan\'s first premiums are set by', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1950-03-01', 'retirement_age' => 65, 'life_expectancy' => 80, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 3_000_000, 'annual_rate' => 0]);

    $plan = runStrategy($user, 'none');

    expect(atAge($plan, 76)['rmd'])->toEqual(126_582)
        ->and(atAge($plan, 76)['irmaa_tier'])->toBe(1)
        ->and(atAge($plan, 77)['irmaa_tier'])->toBe(1);
});

it('charges IRMAA for each of two people on a joint return', function () {
    $user = conversionRetiree();
    Profile::query()->onlyOwnedBy($user)->update(['filing_status' => 'married_joint']);

    expect(atAge(runStrategy($user, 'lump', ['convert_from_age' => 68]), 70)['irmaa'])->toEqual(6936 * 2);
});

/**
 * The tiers rise with prices, and a premium is read against the tiers of its
 * own year. $112,000 earned at 68 is over this year's $109,000 line, but with
 * prices up 5% a year it sets the premium at 70 against a line of $120,173.
 */
it('reads an income against the IRMAA tiers of the year whose premium it sets', function () {
    $user = conversionRetiree();
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 112_000, 'frequency' => 'annual', 'annual_growth_rate' => 0]);

    $plan = runStrategy($user, 'none', ['inflation_rate' => 5]);

    expect(atAge($plan, 68)['magi'])->toEqual(112_000)
        ->and(atAge($plan, 68)['irmaa_lines'][0])->toEqual(120_173)
        ->and(atAge($plan, 68)['magi_tier'])->toBe(0)
        ->and(atAge($plan, 70)['irmaa_tier'])->toBe(0)
        ->and(atAge($plan, 70)['irmaa'])->toEqual(0);
});

it('charges IRMAA from the birthday month in the year Medicare begins', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1962-07-15', 'retirement_age' => 60, 'life_expectancy' => 70, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 100_000, 'annual_rate' => 0]);
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 1_000_000, 'frequency' => 'annual', 'annual_growth_rate' => 0]);

    $plan = runStrategy($user, 'none');

    // The top tier, from July: six months of the year.
    expect(atAge($plan, 64)['irmaa'])->toEqual(0)
        ->and(atAge($plan, 65)['irmaa'])->toEqual(6936 / 2)
        ->and(atAge($plan, 66)['irmaa'])->toEqual(6936);
});

it('counts IRMAA in the total of tax, yours and your heirs\'', function () {
    $summary = runStrategy(conversionRetiree(), 'lump', ['convert_from_age' => 68])['summary'];

    expect($summary['irmaa'])->toBeGreaterThan(0)
        ->and($summary['tax_with_heirs'])->toEqual($summary['lifetime_tax'] + $summary['irmaa'] + $summary['heir_tax']);
});

/**
 * At 56, with $50,000 to spend and nothing but traditional money to spend
 * it from: every dollar drawn owes another 10%, until the year of turning 60.
 * Tax withheld from a conversion is money drawn too.
 */
it('charges the early-withdrawal penalty on money taken from traditional before 59½', function () {
    $user = User::factory()->create();
    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1970-03-01', 'retirement_age' => 50, 'life_expectancy' => 62, 'inflation_rate' => 0]);
    Holding::factory()->retirement('traditional')->create(['user_id' => $user->id, 'balance' => 1_000_000, 'annual_rate' => 0]);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 50_000, 'frequency' => 'annual']);

    $plan = runStrategy($user, 'none');
    $withheld = atAge(runStrategy($user, 'fill_bracket', ['fill_rate' => 12, 'tax_payment' => 'conversion']), 56);

    expect(atAge($plan, 56)['withdrawal'])->toBeGreaterThan(50_000)
        ->and(atAge($plan, 56)['penalty'])->toEqualWithDelta(atAge($plan, 56)['withdrawal'] * 0.1, 1)
        ->and(atAge($plan, 59)['penalty'])->toBeGreaterThan(0)
        ->and(atAge($plan, 60)['withdrawal'])->toBeGreaterThan(0)
        ->and(atAge($plan, 60)['penalty'])->toEqual(0)
        ->and($plan['summary']['penalties'])->toBeGreaterThan(0)
        ->and($withheld['conversion_tax_withheld'])->toBeGreaterThan(0)
        ->and($withheld['penalty'])->toEqualWithDelta(($withheld['withdrawal'] + $withheld['conversion_tax_withheld']) * 0.1, 1);
});

/**
 * A 68-year-old planned only to the end of this year, with no inflation, no
 * traditional money and savings growing 10%: one year, and a known gain.
 */
function saverInFinalYear(float $brokerage, float $yearlyExpense = 0): User
{
    $user = User::factory()->create();

    Profile::query()->create(['user_id' => $user->id, 'birth_date' => '1958-03-01', 'retirement_age' => 65, 'life_expectancy' => 68, 'inflation_rate' => 0]);
    Holding::factory()->ofType('brokerage')->create(['user_id' => $user->id, 'balance' => $brokerage, 'annual_rate' => 0]);

    if ($yearlyExpense > 0) {
        Flow::factory()->create(['user_id' => $user->id, 'amount' => $yearlyExpense, 'frequency' => 'annual']);
    }

    return $user;
}

it('estimates capital gains tax on the growth left in taxable savings, on the owner\'s own income and filing status', function (string $status, float $income, float $gain, float $gainsTax) {
    $user = saverInFinalYear(1_000_000);
    Profile::query()->where('user_id', $user->id)->update(['filing_status' => $status]);

    if ($income > 0) {
        Flow::factory()->income('other_income')->create(['user_id' => $user->id, 'amount' => $income, 'frequency' => 'annual']);
    }

    // The heir's income is set high to show it plays no part.
    $summary = runStrategy($user, 'none', ['growth_rate' => 10, 'heir_income' => 900_000])['summary'];

    expect($summary['taxable_gain'])->toEqual($gain)
        ->and($summary['gains_tax'])->toEqual($gainsTax)
        ->and($summary['leftover_tax'])->toEqual($gainsTax)
        ->and($summary['inheritable'])->toEqual($summary['ending_balance'] - $gainsTax);
})->with([
    // 1,000,000 grows by 100,000; a tenth a year fits inside the unused deduction.
    'no income of their own' => ['single', 0, 100_000, 0],
    // At 68 the deduction is 18,150: 81,850 taxable, $12,719 of tax, so 87,281
    // is saved and the gain is a tenth of 1,087,281. Each year's 10,873 sits
    // wholly in the 15% band.
    'single, earning 100,000' => ['single', 100_000, 108_728, 16_309],
    // The same income on a joint return (deduction 35,500, tax 7,244) leaves
    // the gains inside the 0% band, which runs to 98,900.
    'married, earning 100,000' => ['married_joint', 100_000, 109_276, 0],
]);

it('counts what is saved as basis, and takes a withdrawal\'s share of the basis out with it', function () {
    $user = saverInFinalYear(100_000, 22_000);
    Profile::query()->where('user_id', $user->id)->update(['life_expectancy' => 69]);

    $summary = runStrategy($user, 'none', ['growth_rate' => 10])['summary'];

    // Year one: 22,000 out leaves 78,000, all basis, growing to 85,800.
    // Year two: 22,000 of 85,800 takes 20,000 of the basis, leaving 58,000
    // of basis in 63,800, which grows to 70,180.
    expect($summary['ending_taxable'])->toEqual(70_180)
        ->and($summary['taxable_gain'])->toEqual(12_180);
});

it('adds the heir\'s tax on traditional money to the tax on savings\' gains, and takes both off what is left', function () {
    $summary = runStrategy(conversionRetiree(), 'none', ['growth_rate' => 5, 'heir_income' => 100_000])['summary'];

    expect($summary['heir_tax'])->toBeGreaterThan(0)
        ->and($summary['gains_tax'])->toBeGreaterThan(0)
        ->and($summary['leftover_tax'])->toEqual($summary['heir_tax'] + $summary['gains_tax'])
        ->and($summary['inheritable'])->toEqual($summary['ending_balance'] - $summary['leftover_tax']);
});

it('still counts the owner\'s tax on savings\' gains when a charity inherits', function () {
    $summary = runStrategy(conversionRetiree(), 'none', ['growth_rate' => 5, 'heir_is_charity' => true])['summary'];

    expect($summary['heir_tax'])->toEqual(0)
        ->and($summary['gains_tax'])->toBeGreaterThan(0)
        ->and($summary['leftover_tax'])->toEqual($summary['gains_tax']);
});

it('has an heir pay tax on inherited traditional money over ten years, on top of their own income', function () {
    $user = conversionRetiree();
    $plan = runStrategy($user, 'none', ['heir_income' => 100_000]);

    $left = $plan['summary']['ending_traditional'];
    $tax = new TaxCalculator;

    expect($left)->toBeGreaterThan(0)
        ->and($plan['summary']['heir_tax'])->toEqual(round(10 * ($tax->tax(100_000 + $left / 10, 'single') - $tax->tax(100_000, 'single'))))
        ->and($plan['summary']['tax_with_heirs'])->toEqual($plan['summary']['lifetime_tax'] + $plan['summary']['heir_tax'])
        ->and($plan['summary']['ending_after_heir_tax'])->toEqual($plan['summary']['ending_balance'] - $plan['summary']['heir_tax'])
        // The same inheritance costs a higher earner more.
        ->and(runStrategy($user, 'none', ['heir_income' => 400_000])['summary']['heir_tax'])->toBeGreaterThan($plan['summary']['heir_tax']);
});

it('leaves a charity nothing to pay', function () {
    $plan = runStrategy(conversionRetiree(), 'none', ['heir_is_charity' => true, 'heir_income' => 100_000]);

    expect($plan['summary']['heir_tax'])->toEqual(0)
        ->and($plan['summary']['tax_with_heirs'])->toEqual($plan['summary']['lifetime_tax']);
});

it('leaves heirs nothing to pay on money that was all converted', function () {
    expect(runStrategy(conversionRetiree(), 'lump', ['heir_income' => 100_000])['summary']['heir_tax'])->toEqual(0);
});

// What a strategy is run on

it('builds on the projection its column names, and on the flows as entered when that names none', function () {
    $user = conversionRetiree();
    $pension = Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);
    $scenario = Scenario::factory()->create(['user_id' => $user->id, 'name' => 'Optimistic']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $pension->id, 'overrides' => [2026 => 100_000]]);

    $onScenario = runStrategy($user, 'none', on: $scenario);

    expect(atAge(runStrategy($user, 'none'), 68)['bracket_income'])->toEqual(60_000)
        ->and(atAge($onScenario, 68)['bracket_income'])->toEqual(100_000)
        ->and(atAge($onScenario, 69)['bracket_income'])->toEqual(60_000)
        ->and($onScenario['assumptions']['scenario_name'])->toBe('Optimistic');
});

it('takes its inflation rate from the strategy, then the projection, then the profile', function () {
    $user = conversionRetiree();
    $scenario = Scenario::factory()->create(['user_id' => $user->id, 'bracket_inflation_rate' => 3]);

    expect(runStrategy($user, 'none', ['inflation_rate' => 4], $scenario)['assumptions']['inflation_rate'])->toEqual(4)
        ->and(runStrategy($user, 'none', on: $scenario)['assumptions']['inflation_rate'])->toEqual(3)
        ->and(runStrategy($user, 'none')['assumptions']['inflation_rate'])->toEqual(0);
});

/**
 * Each year is in its own dollars, so the top of a bracket is a line that
 * rises with the strategy's inflation, and a year filled to it rises with it:
 * $50,400 today is $61,262 after four years at 5%.
 */
it('reports each year in its own dollars, against bracket lines that rise with inflation', function () {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12, 'inflation_rate' => 5]);

    expect(atAge($plan, 68)['bracket_lines'][1])->toEqual(50_400)
        ->and(atAge($plan, 68)['taxable_income'])->toEqual(50_400)
        ->and(atAge($plan, 72)['bracket_lines'][1])->toEqual(61_262)
        ->and(atAge($plan, 72)['taxable_income'])->toEqual(61_262);
});

/**
 * Asked for today's dollars, as the Monte Carlo runs do, a bracket is the
 * same line every year whatever the inflation rate: the tables and the
 * dollars rise together.
 */
it('reports today\'s dollars when asked, so the same bracket is filled to the same line every year', function () {
    $user = conversionRetiree();
    $strategy = ConversionStrategy::factory()->reported()->ofKind('fill_bracket', ['growth_rate' => 0, 'fill_rate' => 12, 'inflation_rate' => 5])->create(['user_id' => $user->id]);

    $board = app(ConversionBoard::class);
    ['world' => $world, 'years' => $years] = $board->context($user);
    $rows = collect($board->simulate($strategy, $world, $years[$strategy->reportEntries()->sole()->id], inTodaysDollars: true)['rows'])->keyBy('age');

    expect($rows[68]['bracket_income'])->toEqual(68_550)
        ->and($rows[72]['bracket_income'])->toEqual(68_550)
        ->and($rows[72]['taxable_income'])->toEqual(50_400)
        ->and($rows[72]['bracket_lines'][1])->toEqual(50_400);
});

/**
 * The retiree was born in March, so 70½ falls in the year of turning 70; a
 * birthday in the second half of the year puts it in the year after.
 */
it('carries the charitable distribution limit beside each year from 70½, rising with inflation', function (string $born, int $from) {
    $user = conversionRetiree();
    Profile::query()->onlyOwnedBy($user)->sole()->update(['birth_date' => $born]);

    $strategy = runStrategy($user, 'none', ['inflation_rate' => 10]);

    expect(atAge($strategy, $from - 1)['qcd_limit'])->toBeNull()
        // $111,000 today, two years or three on at 10%.
        ->and(atAge($strategy, $from)['qcd_limit'])->toEqual(round(111_000 * 1.1 ** ($from - 68)))
        ->and(atAge(['rows' => $strategy['today']['rows']], $from)['qcd_limit'])->toEqual(111_000);
})->with([
    'born in March' => ['1958-03-01', 70],
    'born in September' => ['1958-09-01', 71],
]);

it('carries the profile\'s own charitable distribution limit when it has one', function () {
    $user = conversionRetiree();
    Profile::query()->onlyOwnedBy($user)->sole()->update(['qcd_limit' => 40_000]);

    expect(atAge(runStrategy($user, 'none'), 75)['qcd_limit'])->toEqual(40_000);
});

it('grows the balances at the fleet\'s own rate unless the strategy names one', function () {
    $user = conversionRetiree();
    Holding::query()->onlyOwnedBy($user)->update(['annual_rate' => 6]);

    $own = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    expect(collect(app(ConversionBoard::class)->for($user)['report'])->firstWhere('strategy_id', $own->id)['assumptions']['growth_rate'])->toEqual(6)
        ->and(runStrategy($user, 'none', ['growth_rate' => 2])['assumptions']['growth_rate'])->toEqual(2);
});

it('grows each bucket at its own holdings\' rate', function () {
    $user = conversionRetiree(spare: 0);
    Holding::query()->onlyOwnedBy($user)->where('type', 'retirement')->update(['annual_rate' => 8]);
    Holding::query()->onlyOwnedBy($user)->where('type', 'brokerage')->update(['annual_rate' => 2]);

    $plan = runStrategy($user, 'none', ['growth_rate' => null]);

    expect(atAge($plan, 68)['traditional'])->toEqual(1_080_000)
        ->and(atAge($plan, 68)['taxable'])->toEqual(2_040_000)
        // The one figure shown for it is the fleet's blend.
        ->and($plan['assumptions']['growth_rate'])->toEqual(4);
});

it('says at what age the money runs out', function () {
    $user = conversionRetiree(brokerage: 0, spare: 0);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 200_000, 'frequency' => 'annual']);

    expect(runStrategy($user, 'none')['summary']['short_at_age'])->toBeGreaterThan(68)
        ->and(runStrategy(conversionRetiree(), 'none')['summary']['short_at_age'])->toBeNull();
});

it('runs one strategy on each projection it is reported on, as a column each', function () {
    $user = conversionRetiree();
    $pension = Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);
    $scenario = Scenario::factory()->create(['user_id' => $user->id, 'name' => 'Optimistic']);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $pension->id, 'overrides' => [2026 => 100_000]]);
    $strategy = ConversionStrategy::factory()->reported()->reported($scenario)->create(['user_id' => $user->id, 'name' => 'Leave it']);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertInertia(fn ($page) => $page
            ->has('report', 2)
            ->where('report.0.label', 'Leave it · As entered')
            ->where('report.0.strategy_id', $strategy->id)
            ->where('report.0.scenario_id', null)
            ->where('report.0.rows.0.bracket_income', 60_000)
            ->where('report.1.label', 'Leave it · Optimistic')
            ->where('report.1.strategy_id', $strategy->id)
            ->where('report.1.scenario_id', $scenario->id)
            ->where('report.1.rows.0.bracket_income', 100_000)
            ->has('strategies', 1)
            ->where('strategies.0.projections', [null, $scenario->id]));
});

it('takes a strategy\'s column out of the report when its projection is removed, and keeps the strategy', function () {
    $user = conversionRetiree();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $strategy = ConversionStrategy::factory()->reported()->reported($scenario)->create(['user_id' => $user->id]);

    $scenario->delete();

    expect($strategy->fresh()->reportEntries()->pluck('scenario_id')->all())->toBe([null]);
});

// The page

it('gives the page the lines a year is charted against, and each strategy run', function () {
    $user = conversionRetiree();
    ConversionStrategy::factory()->reported()->ofKind('even')->create(['user_id' => $user->id, 'name' => 'Even']);
    ConversionStrategy::factory()->reported()->create(['name' => 'Somebody else\'s']);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Retirement')
            ->where('balances.deferred', 1_000_000)
            ->where('profile.age', 68)
            ->where('brackets.0', ['label' => '10%', 'above' => '12%', 'value' => 12400])
            ->where('brackets.1', ['label' => '12%', 'above' => '22%', 'value' => 50400])
            ->where('irmaa_tiers.0', ['label' => 'No surcharge', 'above' => 'Tier 1', 'value' => 109000, 'surcharge_above' => 1148])
            ->where('defaults.even', [68, 72])
            ->where('defaults.fill_bracket_irmaa', [68, 80])
            ->where('defaults.none', null)
            ->has('report', 1)
            ->where('report.0.name', 'Even')
            ->where('report.0.kind_label', 'Even conversions before RMDs')
            ->has('report.0.rows', 13)
            ->has('report.0.today.rows', 13)
            ->has('report.0.today.summary.tax_with_heirs')
            ->has('report.0.summary.tax_with_heirs'));
});

it('has a tab for each retirement tool, and no others', function (string $tab, string $component) {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('finance.retirement', $tab))->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['social-security', 'SocialSecurity'],
    ['withdrawals', 'Withdrawals'],
]);

it('does not answer for a retirement tab that does not exist', function () {
    $this->actingAs(User::factory()->create())->get('/finance/retirement/annuities')->assertNotFound();
});

// Managing strategies

/**
 * @return array<string, mixed>
 */
function strategyPayload(array $overrides = []): array
{
    return [
        'name' => 'Fill the 22%',
        'kind' => 'fill_bracket',
        'convert_from_age' => null,
        'convert_until_age' => null,
        'fill_rate' => 22,
        'tax_payment' => 'outside',
        'tax_outside_amount' => null,
        'inflation_rate' => null,
        'growth_rate' => null,
        'heir_is_charity' => false,
        'heir_income' => 90_000,
        ...$overrides,
    ];
}

it('adds, edits, copies and removes a strategy', function () {
    $user = conversionRetiree();

    $this->actingAs($user)->post(route('finance.retirement.strategies.store'), strategyPayload())->assertSessionHasNoErrors();
    $strategy = ConversionStrategy::query()->onlyOwnedBy($user)->sole();
    expect($strategy->only(['name', 'kind', 'fill_rate', 'heir_income']))->toBe(['name' => 'Fill the 22%', 'kind' => 'fill_bracket', 'fill_rate' => 22.0, 'heir_income' => 90000.0]);

    $this->actingAs($user)->patch(route('finance.retirement.strategies.update', $strategy), strategyPayload(['name' => 'Fill the 24%', 'fill_rate' => 24, 'heir_is_charity' => true]))->assertSessionHasNoErrors();
    expect($strategy->fresh()->only(['name', 'fill_rate', 'heir_is_charity']))->toBe(['name' => 'Fill the 24%', 'fill_rate' => 24.0, 'heir_is_charity' => true]);

    $this->actingAs($user)->post(route('finance.retirement.strategies.duplicate', $strategy));
    expect(ConversionStrategy::query()->onlyOwnedBy($user)->where('name', 'Fill the 24% copy')->sole()->fill_rate)->toBe(24.0);

    $this->actingAs($user)->delete(route('finance.retirement.strategies.destroy', $strategy));
    expect($strategy->fresh())->toBeNull();
});

it('saves a strategy without a name, and copies it without one', function () {
    $user = conversionRetiree();

    $this->actingAs($user)->post(route('finance.retirement.strategies.store'), strategyPayload(['name' => '']))->assertSessionHasNoErrors();
    $strategy = ConversionStrategy::query()->onlyOwnedBy($user)->sole();

    $this->actingAs($user)->post(route('finance.retirement.strategies.duplicate', $strategy));

    expect(ConversionStrategy::query()->onlyOwnedBy($user)->pluck('name')->all())->toBe([null, null]);
});

it('calls a strategy by its name, or by its kind when it has none', function () {
    $user = conversionRetiree();
    ConversionStrategy::factory()->ofKind('even')->create(['user_id' => $user->id]);
    ConversionStrategy::factory()->ofKind('even')->create(['user_id' => $user->id, 'name' => 'My pick']);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertInertia(fn ($page) => $page
            ->where('strategies.0.name', null)
            ->where('strategies.0.label', 'Even conversions before RMDs')
            ->where('strategies.1.label', 'My pick'));
});

it('adds one strategy of each kind to start from', function () {
    $user = conversionRetiree();

    $this->actingAs($user)->post(route('finance.retirement.strategies.starters'));

    expect(ConversionStrategy::query()->onlyOwnedBy($user)->orderBy('id')->pluck('kind')->all())->toBe(['none', 'lump', 'even', 'fixed', 'fill_bracket', 'fill_bracket_irmaa'])
        ->and(ConversionStrategy::query()->onlyOwnedBy($user)->pluck('heir_income')->unique()->all())->toBe([100000.0])
        ->and(ConversionStrategy::query()->onlyOwnedBy($user)->where('kind', 'fixed')->value('conversion_amount'))->toEqual(100_000);
});

it('refuses a strategy it could not run', function (array $overrides, string $field) {
    $user = conversionRetiree();

    $this->actingAs($user)->post(route('finance.retirement.strategies.store'), strategyPayload($overrides))->assertSessionHasErrors($field);

    expect(ConversionStrategy::query()->count())->toBe(0);
})->with([
    'a kind it does not know' => [['kind' => 'backdoor'], 'kind'],
    'a bracket that is not on the table' => [['fill_rate' => 15], 'fill_rate'],
    'a window entered back to front' => [['convert_from_age' => 72, 'convert_until_age' => 65], 'convert_until_age'],
    'inflation off the scale' => [['inflation_rate' => 40], 'inflation_rate'],
    'a way of paying the tax it does not know' => [['tax_payment' => 'later'], 'tax_payment'],
    'a split with no amount' => [['tax_payment' => 'percent'], 'tax_outside_amount'],
    'a fixed amount with no amount' => [['kind' => 'fixed'], 'conversion_amount'],
    'a fixed amount of nothing' => [['kind' => 'fixed', 'conversion_amount' => 0], 'conversion_amount'],
    'more than all of the tax from outside' => [['tax_payment' => 'percent', 'tax_outside_amount' => 150], 'tax_outside_amount'],
]);

it('hides another user\'s strategy behind a 404', function (string $method, string $route) {
    $strategy = ConversionStrategy::factory()->reported()->create(['name' => 'Theirs']);

    $this->actingAs(conversionRetiree())->{$method}(route($route, $strategy), strategyPayload())->assertNotFound();

    expect(ConversionStrategy::query()->pluck('name')->all())->toBe(['Theirs']);
})->with([
    'update' => ['patch', 'finance.retirement.strategies.update'],
    'duplicate' => ['post', 'finance.retirement.strategies.duplicate'],
    'destroy' => ['delete', 'finance.retirement.strategies.destroy'],
]);

// Years set by hand

it('converts what a year set by hand says, and leaves every other year to the strategy', function () {
    // 70 in 2028. The usual window is 65 through 72, so 73 is outside it.
    $plan = runStrategy(conversionRetiree(), 'fixed', ['conversion_amount' => 200_000, 'overrides' => [
        2028 => ['conversion' => 50_000, 'withheld' => null],
        2031 => ['conversion' => 20_000, 'withheld' => null],
    ]]);

    expect(collect($plan['rows'])->whereBetween('age', [68, 73])->pluck('conversion')->all())->toEqual([200_000, 200_000, 50_000, 200_000, 200_000, 20_000]);
});

it('converts no more in a year set by hand than is left to convert', function () {
    $plan = runStrategy(conversionRetiree(), 'none', ['overrides' => [2026 => ['conversion' => 5_000_000, 'withheld' => null]]]);

    expect(atAge($plan, 68)['conversion'])->toEqual(1_000_000)
        ->and(atAge($plan, 68)['traditional'])->toEqual(0);
});

it('holds an amount set by hand in today\'s dollars', function () {
    $plan = runStrategy(conversionRetiree(), 'none', ['inflation_rate' => 10, 'overrides' => [2027 => ['conversion' => 50_000, 'withheld' => 1_000]]]);

    expect(atAge($plan, 69))->toMatchArray(['conversion' => 55_000.0, 'conversion_tax_withheld' => 1_100.0])
        ->and(collect($plan['today']['rows'])->firstWhere('age', 69))->toMatchArray(['conversion' => 50_000.0, 'conversion_tax_withheld' => 1_000.0]);
});

it('converts an amount set by hand even when the year has nothing spare to pay its tax from', function () {
    $user = conversionRetiree(spare: 0);
    Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 40_000, 'frequency' => 'annual']);
    Flow::factory()->create(['user_id' => $user->id, 'amount' => 60_000, 'frequency' => 'annual']);

    $plan = runStrategy($user, 'fill_bracket', ['fill_rate' => 12, 'overrides' => [2026 => ['conversion' => 30_000, 'withheld' => null]]]);

    expect(atAge($plan, 68)['conversion'])->toEqual(30_000)
        // The tax is paid from outside the conversion, so all of it reaches the Roth.
        ->and(atAge($plan, 68)['conversion_tax_withheld'])->toEqual(0)
        ->and(atAge($plan, 68)['roth'])->toEqual(30_000)
        ->and(atAge($plan, 69)['conversion'])->toEqual(0);
});

/*
 * The year of the tax-payment test above: $68,550 fills the 12% bracket and
 * adds $5,800 of tax.
 */
it('takes the tax set by hand out of the converted money, and no more than the tax comes to', function (array $year, int $fromConversion) {
    $plan = runStrategy(conversionRetiree(), 'fill_bracket', ['fill_rate' => 12, 'convert_from_age' => 68, 'convert_until_age' => 68, 'overrides' => [2026 => $year]]);

    expect(atAge($plan, 68)['conversion'])->toEqual(68_550)
        ->and(atAge($plan, 68)['conversion_tax'])->toEqual(5800)
        ->and(atAge($plan, 68)['conversion_tax_withheld'])->toEqual($fromConversion)
        ->and(atAge($plan, 68)['roth'])->toEqual(68_550 - $fromConversion)
        ->and(atAge($plan, 68)['taxable'])->toEqual(2_000_000 + 1_000_000 - (5800 - $fromConversion));
})->with([
    'part of it' => [['conversion' => null, 'withheld' => 2_000], 2_000],
    'none of it, the amount set too' => [['conversion' => 68_550, 'withheld' => 0], 0],
    'more than there is' => [['conversion' => null, 'withheld' => 50_000], 5_800],
]);

it('marks an unnamed strategy with a year set by hand, and leaves a name alone', function () {
    $user = conversionRetiree();
    $overrides = [2028 => ['conversion' => 50_000, 'withheld' => null]];
    ConversionStrategy::factory()->reported()->ofKind('even')->create(['user_id' => $user->id, 'overrides' => $overrides]);
    ConversionStrategy::factory()->reported()->ofKind('even')->create(['user_id' => $user->id, 'overrides' => $overrides, 'name' => 'My pick']);
    ConversionStrategy::factory()->reported()->ofKind('even')->create(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertInertia(fn ($page) => $page
            ->where('strategies.0.label', 'Even conversions before RMDs [C]')
            ->where('strategies.0.is_customized', true)
            ->where('strategies.1.label', 'My pick')
            ->where('strategies.2.label', 'Even conversions before RMDs')
            ->where('strategies.2.is_customized', false));
});

it('keeps the years set by hand, dropping any with nothing set', function () {
    $user = conversionRetiree();
    $strategy = ConversionStrategy::factory()->reported()->ofKind('even')->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch(route('finance.retirement.strategies.update', $strategy), strategyPayload(['overrides' => [
        2028 => ['conversion' => 50_000, 'withheld' => null],
        2029 => ['conversion' => null, 'withheld' => null],
        2030 => ['conversion' => null, 'withheld' => 0],
    ]]))->assertSessionHasNoErrors();

    expect($strategy->fresh()->overrides)->toBe([2028 => ['conversion' => 50_000, 'withheld' => null], 2030 => ['conversion' => null, 'withheld' => 0]]);

    $this->actingAs($user)->patch(route('finance.retirement.strategies.update', $strategy), strategyPayload(['overrides' => [2029 => ['conversion' => null, 'withheld' => null]]]))->assertSessionHasNoErrors();

    expect($strategy->fresh()->overrides)->toBeNull()
        ->and($strategy->fresh()->is_customized)->toBeFalse();
});

it('refuses a year set by hand that it could not run', function (array $overrides) {
    $user = conversionRetiree();

    $this->actingAs($user)->post(route('finance.retirement.strategies.store'), strategyPayload(['overrides' => $overrides]))->assertSessionHasErrors();

    expect(ConversionStrategy::query()->count())->toBe(0);
})->with([
    'a year gone by' => [[2025 => ['conversion' => 50_000]]],
    'something that is not a year' => [['soon' => ['conversion' => 50_000]]],
    'a conversion below nothing' => [[2028 => ['conversion' => -1]]],
    'tax below nothing' => [[2028 => ['withheld' => -1]]],
    'a figure it does not know' => [[2028 => ['conversion' => 50_000, 'roth' => 50_000]]],
]);

it('previews what settings would convert each year, in today\'s dollars, without keeping them', function () {
    $user = conversionRetiree();

    $rows = $this->actingAs($user)
        ->postJson(route('finance.retirement.strategies.preview'), strategyPayload(['kind' => 'fixed', 'conversion_amount' => 300_000, 'growth_rate' => 0, 'overrides' => [2027 => ['conversion' => 50_000, 'withheld' => 1_000]]]))
        ->assertOk()
        ->json('rows');

    expect($rows)->toHaveCount(13)
        ->and(array_keys($rows[0]))->toBe(['age', 'year', 'conversion', 'conversion_tax', 'conversion_tax_withheld', 'traditional'])
        ->and(array_column($rows, 'conversion', 'year'))->toMatchArray([2026 => 300_000, 2027 => 50_000, 2028 => 300_000])
        ->and($rows[1]['conversion_tax_withheld'])->toEqual(1_000)
        ->and(ConversionStrategy::query()->count())->toBe(0);
});

it('previews on the projection asked for', function () {
    $user = conversionRetiree(spare: 0);
    $pension = Flow::factory()->income('pension')->create(['user_id' => $user->id, 'amount' => 20_000, 'frequency' => 'annual']);
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    ScenarioFlow::factory()->create(['scenario_id' => $scenario->id, 'flow_id' => $pension->id, 'overrides' => [2026 => 60_000]]);

    $converted = fn (?int $scenarioId): float => $this->actingAs($user)
        ->postJson(route('finance.retirement.strategies.preview'), strategyPayload(['tax_payment' => 'conversion', 'growth_rate' => 0, 'scenario_id' => $scenarioId]))
        ->assertOk()
        ->json('rows.0.conversion');

    // The same bracket has $40,000 less room in it on the projection.
    expect($converted(null) - $converted($scenario->id))->toEqual(40_000);
});

it('will not preview settings it could not save', function (array $overrides, string $field) {
    $this->actingAs(conversionRetiree())
        ->postJson(route('finance.retirement.strategies.preview'), strategyPayload($overrides))
        ->assertJsonValidationErrors($field);
})->with([
    'a kind it does not know' => [['kind' => 'backdoor'], 'kind'],
    'someone else\'s projection' => [fn () => ['scenario_id' => Scenario::factory()->create()->id], 'scenario_id'],
    'a year gone by' => [['overrides' => [2025 => ['conversion' => 50_000]]], 'overrides'],
]);

it('asks a guest to sign in before previewing', function () {
    $this->postJson(route('finance.retirement.strategies.preview'), strategyPayload())->assertUnauthorized();
});

// The report

it('runs only what is in the report, and lists every strategy as settings alone', function () {
    $user = conversionRetiree();
    $scenario = Scenario::factory()->create(['user_id' => $user->id, 'name' => 'Lean years']);
    ConversionStrategy::factory()->reported($scenario)->ofKind('even')->create(['user_id' => $user->id, 'name' => 'Reported']);
    ConversionStrategy::factory()->ofKind('lump')->create(['user_id' => $user->id, 'name' => 'Waiting']);

    $this->actingAs($user)->get(route('finance.retirement'))
        ->assertInertia(fn ($page) => $page
            ->has('report', 1)
            ->where('report.0.name', 'Reported')
            ->where('report.0.assumptions.scenario_name', 'Lean years')
            ->has('report.0.rows')
            ->has('strategies', 2)
            ->where('strategies.0.projections', [$scenario->id])
            ->where('strategies.1.name', 'Waiting')
            ->where('strategies.1.kind_label', 'One large conversion')
            ->where('strategies.1.projections', [])
            ->missing('strategies.0.rows')
            ->missing('strategies.1.summary')
            ->where('comparison', ['count' => 1, 'default' => 6, 'max' => 12]));
});

it('leaves a strategy in no report out of the Monte Carlo runs', function () {
    $user = conversionRetiree();
    ConversionStrategy::factory()->reported()->ofKind('even')->create(['user_id' => $user->id]);
    ConversionStrategy::factory()->ofKind('lump')->create(['user_id' => $user->id]);

    $monteCarlo = app(ConversionMonteCarlo::class)->for($user);

    expect($monteCarlo['simulations'])->toBe(MonteCarloRun::for($user)->runs)
        ->and($monteCarlo['results']['strategies'])->toHaveCount(1);
});

it('keeps a new strategy out of the report until it is added', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.retirement.strategies.store'), strategyPayload())->assertSessionHasNoErrors();

    expect(ConversionStrategy::query()->onlyOwnedBy($user)->sole()->reportEntries()->count())->toBe(0);
});

it('leaves a strategy where it is in the report when it is edited', function () {
    $user = User::factory()->create();
    $strategy = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch(route('finance.retirement.strategies.update', $strategy), strategyPayload(['name' => 'Renamed']))->assertSessionHasNoErrors();

    expect($strategy->fresh()->name)->toBe('Renamed')
        ->and($strategy->reportEntries()->count())->toBe(1);
});

it('copies a strategy onto the same projections, and flashes the copy to be opened', function () {
    $user = User::factory()->create();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $strategy = ConversionStrategy::factory()->reported()->reported($scenario)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post(route('finance.retirement.strategies.duplicate', $strategy));

    $copy = ConversionStrategy::query()->latest('id')->first();
    $response->assertSessionHas('copied', $copy->id);
    expect($copy->reportEntries()->pluck('scenario_id')->all())->toBe([null, $scenario->id]);
});

it('copies a strategy into only as much of the report as there is room for', function () {
    $user = User::factory()->create();
    ConversionStrategy::factory()->count(11)->reported()->create(['user_id' => $user->id]);
    $strategy = ConversionStrategy::query()->onlyOwnedBy($user)->first();

    $this->actingAs($user)->post(route('finance.retirement.strategies.duplicate', $strategy));
    $this->actingAs($user)->post(route('finance.retirement.strategies.duplicate', $strategy))
        ->assertSessionHas('success', 'No conversion made from No conversion. The report is full, so not every column of it was copied.');

    expect(ConversionReportEntry::query()->onlyOwnedBy($user)->count())->toBe(12)
        ->and(ConversionStrategy::query()->onlyOwnedBy($user)->count())->toBe(13);
});

it('adds a strategy to the report on the projections named, once each', function () {
    $user = User::factory()->create();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $strategy = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post(route('finance.retirement.report.store'), ['strategy_id' => $strategy->id, 'projections' => [null, $scenario->id]])->assertSessionHasNoErrors();

    expect($strategy->reportEntries()->pluck('scenario_id')->all())->toBe([null, $scenario->id]);
});

it('will not add a thirteenth column to the report', function () {
    $user = User::factory()->create();
    ConversionStrategy::factory()->count(12)->reported()->create(['user_id' => $user->id]);
    $strategy = ConversionStrategy::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post(route('finance.retirement.report.store'), ['strategy_id' => $strategy->id, 'projections' => [null]])
        ->assertSessionHas('error', 'No more than 12 can be in the report at once. Take some out first.');

    expect($strategy->reportEntries()->count())->toBe(0);
});

it('refuses to add to the report what is not the user\'s own', function (Closure $payload, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.retirement.report.store'), $payload($user))->assertSessionHasErrors($field);

    expect(ConversionReportEntry::query()->count())->toBe(0);
})->with([
    'another user\'s strategy' => [fn () => ['strategy_id' => ConversionStrategy::factory()->create()->id, 'projections' => [null]], 'strategy_id'],
    'another user\'s projection' => [fn (User $user) => ['strategy_id' => ConversionStrategy::factory()->create(['user_id' => $user->id])->id, 'projections' => [Scenario::factory()->create()->id]], 'projections.0'],
    'no projection at all' => [fn (User $user) => ['strategy_id' => ConversionStrategy::factory()->create(['user_id' => $user->id])->id, 'projections' => []], 'projections'],
]);

it('takes one column out of the report and leaves its strategy and its other columns', function () {
    $user = User::factory()->create();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $strategy = ConversionStrategy::factory()->reported()->reported($scenario)->create(['user_id' => $user->id]);

    $this->actingAs($user)->delete(route('finance.retirement.report.destroy', $strategy->reportEntries()->whereNull('scenario_id')->sole()))->assertRedirect();

    expect($strategy->fresh()->reportEntries()->pluck('scenario_id')->all())->toBe([$scenario->id]);
});

it('hides another user\'s column of the report behind a 404', function () {
    $theirs = ConversionReportEntry::factory()->create();

    $this->actingAs(User::factory()->create())->delete(route('finance.retirement.report.destroy', $theirs))->assertNotFound();

    expect($theirs->fresh())->not->toBeNull();
});

it('replaces the report with each strategy named on each projection named', function () {
    $user = User::factory()->create();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $leaves = ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);
    [$first, $second] = ConversionStrategy::factory()->count(2)->create(['user_id' => $user->id])->all();
    $theirs = ConversionReportEntry::factory()->create();

    $this->actingAs($user)->put(route('finance.retirement.report.replace'), ['strategies' => [$first->id, $second->id], 'projections' => [null, $scenario->id]])->assertSessionHasNoErrors();

    expect(ConversionReportEntry::query()->onlyOwnedBy($user)->inDefaultOrder()->get()->map->only(['conversion_strategy_id', 'scenario_id'])->all())->toBe([
        ['conversion_strategy_id' => $first->id, 'scenario_id' => null],
        ['conversion_strategy_id' => $second->id, 'scenario_id' => null],
        ['conversion_strategy_id' => $first->id, 'scenario_id' => $scenario->id],
        ['conversion_strategy_id' => $second->id, 'scenario_id' => $scenario->id],
    ])
        ->and($leaves->fresh())->not->toBeNull()
        // Another user's report is not theirs to empty.
        ->and($theirs->fresh())->not->toBeNull();
});

it('clears the report without removing a strategy', function () {
    $user = User::factory()->create();
    ConversionStrategy::factory()->count(2)->reported()->create(['user_id' => $user->id]);
    $theirs = ConversionReportEntry::factory()->create();

    $this->actingAs($user)->delete(route('finance.retirement.report.clear'))->assertRedirect();

    expect(ConversionReportEntry::query()->onlyOwnedBy($user)->count())->toBe(0)
        ->and(ConversionStrategy::query()->onlyOwnedBy($user)->count())->toBe(2)
        // Another user's report is left as it was.
        ->and($theirs->fresh())->not->toBeNull();
});

it('refuses a report it cannot make', function (Closure $payload, string $field, string $message) {
    $user = User::factory()->create();
    ConversionStrategy::factory()->reported()->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('finance.retirement.report.replace'), $payload($user))
        ->assertSessionHasErrors([$field => $message]);

    expect(ConversionReportEntry::query()->onlyOwnedBy($user)->count())->toBe(1);
})->with([
    'of no strategy' => [fn () => ['strategies' => [], 'projections' => [null]], 'strategies', 'Pick at least one strategy for the report.'],
    'on no projection' => [fn (User $user) => ['strategies' => ConversionStrategy::query()->onlyOwnedBy($user)->pluck('id')->all(), 'projections' => []], 'projections', 'Pick at least one projection to run them on.'],
    'of more than twelve columns' => [
        fn (User $user) => [
            'strategies' => ConversionStrategy::factory()->count(7)->create(['user_id' => $user->id])->modelKeys(),
            'projections' => [null, Scenario::factory()->create(['user_id' => $user->id])->id],
        ],
        'strategies', 'No more than 12 can be in the report at once: 7 strategies on 2 projections is 14.',
    ],
    'of another user\'s strategy' => [fn () => ['strategies' => [ConversionStrategy::factory()->create()->id], 'projections' => [null]], 'strategies.0', 'The selected strategies.0 is invalid.'],
    'on another user\'s projection' => [fn (User $user) => ['strategies' => ConversionStrategy::query()->onlyOwnedBy($user)->pluck('id')->all(), 'projections' => [Scenario::factory()->create()->id]], 'projections.0', 'The selected projections.0 is invalid.'],
]);

// Starting with one of each kind

it('puts one of each kind in the report on the projection named, each unnamed', function () {
    $user = User::factory()->create();
    $scenario = Scenario::factory()->create(['user_id' => $user->id, 'name' => 'Lean years']);

    $this->actingAs($user)->post(route('finance.retirement.strategies.starters'), ['scenario_id' => $scenario->id])
        ->assertSessionHas('success', 'One strategy of each kind is in the report on Lean years.');

    $made = ConversionStrategy::query()->onlyOwnedBy($user)->orderBy('id')->get();
    expect($made->pluck('kind')->all())->toBe(['none', 'lump', 'even', 'fixed', 'fill_bracket', 'fill_bracket_irmaa'])
        ->and($made->pluck('name')->unique()->all())->toBe([null])
        ->and($made->first()->label)->toBe('No conversion')
        ->and(ConversionReportEntry::query()->onlyOwnedBy($user)->pluck('scenario_id')->all())->toBe(array_fill(0, 6, $scenario->id));
});

it('uses the starters already made when asked for them on another projection, and makes again only one that was changed', function () {
    $user = User::factory()->create();
    $scenario = Scenario::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user)->post(route('finance.retirement.strategies.starters'));
    ConversionReportEntry::query()->onlyOwnedBy($user)->delete();
    ConversionStrategy::query()->onlyOwnedBy($user)->where('kind', 'even')->sole()->update(['convert_from_age' => 70]);

    $this->actingAs($user)->post(route('finance.retirement.strategies.starters'), ['scenario_id' => $scenario->id]);

    expect(ConversionStrategy::query()->onlyOwnedBy($user)->count())->toBe(7)
        ->and(ConversionStrategy::query()->onlyOwnedBy($user)->where('kind', 'even')->count())->toBe(2)
        ->and(ConversionReportEntry::query()->onlyOwnedBy($user)->count())->toBe(6);
});

it('puts the one set on every saved projection, filling the report to six and no further', function () {
    $user = User::factory()->create();
    $first = Scenario::factory()->create(['user_id' => $user->id, 'name' => 'A cautious']);
    Scenario::factory()->create(['user_id' => $user->id, 'name' => 'B hopeful']);
    Scenario::factory()->create(['name' => 'Somebody else\'s']);

    $this->actingAs($user)->post(route('finance.retirement.strategies.starters'), ['every_projection' => true])
        ->assertSessionHas('success', 'One strategy of each kind is ready. 6 of the 12 it comes to on each of your 2 projections joined the report, which fills no further by itself; add the rest from the strategies above it.');

    expect(ConversionStrategy::query()->onlyOwnedBy($user)->count())->toBe(6)
        ->and(ConversionReportEntry::query()->onlyOwnedBy($user)->pluck('scenario_id')->all())->toBe(array_fill(0, 6, $first->id));
});

it('puts the set on the income and expenses as entered when no projection is saved', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.retirement.strategies.starters'), ['every_projection' => true])
        ->assertSessionHas('success', 'One strategy of each kind is in the report.');

    expect(ConversionStrategy::query()->onlyOwnedBy($user)->count())->toBe(6)
        ->and(ConversionReportEntry::query()->onlyOwnedBy($user)->pluck('scenario_id')->all())->toBe(array_fill(0, 6, null));
});

it('will not make a set on another user\'s projection', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('finance.retirement.strategies.starters'), ['scenario_id' => Scenario::factory()->create()->id])
        ->assertSessionHasErrors('scenario_id');

    expect(ConversionStrategy::query()->count())->toBe(0);
});
