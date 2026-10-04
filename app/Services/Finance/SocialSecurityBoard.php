<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Models\Finance\Holding;
use App\Models\Finance\Profile;
use App\Models\Finance\SocialSecurityStrategy;
use App\Models\User;

/**
 * The Retirement Strategizer's Social Security tab: every saved claiming
 * strategy, run over the same lifetimes so they can be set side by side.
 *
 * A strategy is an age to claim at for each person. What it is worth follows
 * from two facts the profile carries — each person's monthly benefit at full
 * retirement age, as their Social Security statement gives it — and the rules
 * in config `finance.social_security`:
 *
 *  - Claimed before full retirement age a benefit is reduced for good;
 *    claimed after, it earns delayed credits up to 70.
 *  - A spouse whose own full benefit is less than half the other's is topped
 *    up to it once both have claimed. The top-up is reduced if it starts
 *    before the spouse's own full retirement age and earns nothing for waiting.
 *  - When one dies, the survivor keeps the larger of the two benefits.
 *
 * And its limits: no earnings test for someone claiming while still working,
 * no restricted or deemed-filing rules, no widow's limit, no children's
 * benefits, and each person is taken to live to the end of the year they
 * reach their life expectancy.
 *
 * Benefits are in today's dollars. A cost-of-living rate different from the
 * strategy's inflation makes them drift against prices, which is the point of
 * being able to set it.
 *
 * How much of a benefit is taxed is the provisional-income test, run against
 * the rest of the household's taxed income as projected. Its thresholds are
 * not indexed, so the test is made in the dollars of each year.
 */
class SocialSecurityBoard
{
    public function __construct(
        private Fleet $fleet,
        private ScenarioBoard $scenarios,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $profile = Profile::for($user);
        $people = $this->people($profile);
        $other = $this->otherIncome($user, $profile);
        $growthRate = $this->growthRate($user);
        $config = config('finance.social_security');

        $strategies = SocialSecurityStrategy::query()->onlyOwnedBy($user)->inDefaultOrder()->get()
            ->map(fn (SocialSecurityStrategy $strategy): array => [
                ...$strategy->props,
                ...$this->simulate($strategy, $profile, $people, $other, $growthRate),
            ])->values();

        return [
            'profile' => [
                'age' => $profile->age,
                'has_birth_date' => $profile->birth_date !== null,
                'life_expectancy' => $profile->life_expectancy,
                'inflation_rate' => $profile->inflation_rate,
                'filing_status' => config("finance.tax.filing_statuses.{$profile->filing_status}", $profile->filing_status),
            ],
            // What the benefits form is filled from.
            'benefits' => [
                'ss_monthly_benefit' => $profile->ss_monthly_benefit,
                'spouse_birth_date' => $profile->spouse_birth_date?->toDateString(),
                'spouse_ss_monthly_benefit' => $profile->spouse_ss_monthly_benefit,
                'spouse_life_expectancy' => $profile->spouse_life_expectancy,
            ],
            'people' => collect($people)->map(fn (array $person): array => [
                'label' => $person['label'],
                'benefit' => $person['benefit'],
                'full_retirement_age' => ['years' => intdiv($person['fra'], 12), 'months' => $person['fra'] % 12],
                'life_expectancy' => $person['life_expectancy'],
            ])->all(),
            'has_spouse' => isset($people['spouse']),
            'has_benefit' => collect($people)->contains(fn (array $person): bool => $person['benefit'] > 0),
            'ages' => ['earliest' => $config['earliest_age'], 'latest' => $config['latest_age']],
            'growth_rate' => $growthRate,
            'strategies' => $this->withBreakevens($strategies),
        ];
    }

    /**
     * One strategy, year by year.
     *
     * @param  array<string, array<string, mixed>>  $people  From people().
     * @param  array<int, float>  $other  The household's other taxed income each year, in that year's dollars.
     * @return array{assumptions: array<string, mixed>, claims: array<string, array<string, mixed>>, rows: list<array<string, float|int|null>>, summary: array<string, float|int|null>}
     */
    public function simulate(SocialSecurityStrategy $strategy, Profile $profile, array $people, array $other, float $growthRate): array
    {
        $colaRate = $strategy->cola_rate ?? $profile->inflation_rate;
        $discountRate = $strategy->discount_rate ?? $growthRate;
        $inflation = 1 + $profile->inflation_rate / 100;
        $thisYear = now()->year;

        $claims = $this->claims($strategy, $people);

        // Month numbers count from year 0, so a year's twelve are
        // [year * 12, year * 12 + 11].
        $lastYear = max(array_map(fn (array $person): int => $person['death_year'], $people));
        $thresholds = config("finance.social_security.taxation.{$profile->filing_status}") ?? config('finance.social_security.taxation.single');

        $rows = [];
        $cumulative = 0.0;
        $presentValue = 0.0;
        $taxableTotal = 0.0;

        for ($year = $thisYear; $year <= $lastYear; $year++) {
            $paid = array_fill_keys(array_keys($people), 0.0);

            for ($month = $year * 12; $month < $year * 12 + 12; $month++) {
                foreach ($this->monthly($month, $people, $claims) as $key => $amount) {
                    $paid[$key] += $amount;
                }
            }

            // In today's dollars: a COLA that matches inflation holds a
            // benefit still, and one that does not lets it drift.
            $real = ((1 + $colaRate / 100) / $inflation) ** ($year - $thisYear);
            $paid = array_map(fn (float $amount): float => $amount * $real, $paid);
            $total = array_sum($paid);

            // The test is made in the year's own dollars.
            $index = $inflation ** ($year - $thisYear);
            $taxable = $this->taxable($total * $index, $other[$year] ?? 0.0, $thresholds) / $index;

            $cumulative += $total;
            $taxableTotal += $taxable;
            // Discounted at what the money could have earned over inflation.
            $presentValue += $total / ((1 + $discountRate / 100) / $inflation) ** ($year - $thisYear);

            $rows[] = [
                'year' => $year,
                'age' => $year - $profile->birth_year,
                'self' => round($paid['self']),
                'spouse' => isset($paid['spouse']) ? round($paid['spouse']) : null,
                'total' => round($total),
                'cumulative' => round($cumulative),
                'taxable' => round($taxable),
                'taxable_share' => $total > 0 ? round($taxable / $total * 100, 1) : 0.0,
            ];
        }

        return [
            'assumptions' => ['cola_rate' => $colaRate, 'discount_rate' => $discountRate],
            'claims' => $claims,
            'rows' => $rows,
            'summary' => [
                'lifetime' => round($cumulative),
                'present_value' => round($presentValue),
                'first_year' => collect($rows)->firstWhere('total', '>', 0)['year'] ?? null,
                // The household's benefit once everyone has claimed.
                'monthly' => round(array_sum(array_column($claims, 'monthly'))),
                'taxable_share' => $cumulative > 0 ? round($taxableTotal / $cumulative * 100, 1) : 0.0,
            ],
        ];
    }

    /**
     * The people a strategy claims for: the profile's owner, and a spouse
     * when the profile has one. Ages are in months throughout.
     *
     * @return array<string, array{label: string, benefit: float, birth_month: int, fra: int, life_expectancy: int, death_year: int}>
     */
    public function people(Profile $profile): array
    {
        $birth = $profile->birth_date ?? Carbon::create($profile->birth_year, 1, 1);

        $people = ['self' => $this->person('You', $birth, (float) $profile->ss_monthly_benefit, $profile->life_expectancy)];

        if ($profile->spouse_birth_date !== null) {
            $people['spouse'] = $this->person('Spouse', $profile->spouse_birth_date, (float) $profile->spouse_ss_monthly_benefit, $profile->spouse_life_expectancy ?? $profile->life_expectancy);
        }

        return $people;
    }

    /**
     * @return array{label: string, benefit: float, birth_month: int, fra: int, life_expectancy: int, death_year: int}
     */
    private function person(string $label, Carbon $birth, float $benefit, int $lifeExpectancy): array
    {
        return [
            'label' => $label,
            'benefit' => $benefit,
            // The month they were born in, counted from year 0.
            'birth_month' => $birth->year * 12 + $birth->month - 1,
            'fra' => $this->fullRetirementAge($birth->year),
            'life_expectancy' => $lifeExpectancy,
            'death_year' => $birth->year + $lifeExpectancy,
        ];
    }

    /**
     * Full retirement age for a birth year, in months.
     */
    public function fullRetirementAge(int $birthYear): int
    {
        foreach (config('finance.social_security.full_retirement_age') as [$through, $years, $months]) {
            if ($through === null || $birthYear <= $through) {
                return $years * 12 + $months;
            }
        }

        return 67 * 12;
    }

    /**
     * What a benefit claimed at an age comes to, as a share of the full one:
     * reduced before full retirement age, credited after it up to 70.
     *
     * @param  int  $claimAge  In months.
     * @param  int  $fullAge  Full retirement age, in months.
     */
    public function ownFactor(int $claimAge, int $fullAge): float
    {
        $config = config('finance.social_security');

        if ($claimAge >= $fullAge) {
            return 1 + (min($claimAge, $config['latest_age'] * 12) - $fullAge) * $config['delayed_credit'] / 100;
        }

        return 1 - $this->reduction($fullAge - $claimAge, $config['early_reduction']);
    }

    /**
     * The same for the top-up a spouse draws on the other's record: reduced
     * when it starts early, and never worth more for waiting.
     */
    public function spousalFactor(int $startAge, int $fullAge): float
    {
        if ($startAge >= $fullAge) {
            return 1.0;
        }

        return 1 - $this->reduction($fullAge - $startAge, config('finance.social_security.spousal_reduction'));
    }

    /**
     * @param  array{first_months: int, first_rate: float, later_rate: float}  $rule
     */
    private function reduction(int $monthsEarly, array $rule): float
    {
        return (min($monthsEarly, $rule['first_months']) * $rule['first_rate'] + max(0, $monthsEarly - $rule['first_months']) * $rule['later_rate']) / 100;
    }

    /**
     * When each person claims and what they get, in today's dollars a month:
     * their own benefit, and the top-up on the other's record once both have
     * claimed.
     *
     * @param  array<string, array<string, mixed>>  $people
     * @return array<string, array{age: int, months: int, claim_month: int, starts_on: string, own: float, top_up: float, top_up_month: int|null, monthly: float}>
     */
    public function claims(SocialSecurityStrategy $strategy, array $people): array
    {
        $config = config('finance.social_security');
        $claims = [];

        foreach ($people as $key => $person) {
            $age = $key === 'spouse' ? ($strategy->spouse_claim_age ?? $strategy->claim_age) : $strategy->claim_age;
            $months = $key === 'spouse' ? ($strategy->spouse_claim_age === null ? $strategy->claim_months : $strategy->spouse_claim_months) : $strategy->claim_months;
            $claimAge = min($config['latest_age'] * 12, max($config['earliest_age'] * 12, $age * 12 + $months));
            // Nobody claims in the past: someone already older claims now.
            $claimMonth = max($person['birth_month'] + $claimAge, now()->year * 12 + now()->month - 1);

            $claims[$key] = [
                'age' => intdiv($claimMonth - $person['birth_month'], 12),
                'months' => ($claimMonth - $person['birth_month']) % 12,
                'claim_month' => $claimMonth,
                'starts_on' => Carbon::create(intdiv($claimMonth, 12), $claimMonth % 12 + 1, 1)->toDateString(),
                'own' => $person['benefit'] * $this->ownFactor($claimMonth - $person['birth_month'], $person['fra']),
                'top_up' => 0.0,
                'top_up_month' => null,
                'monthly' => 0.0,
            ];
        }

        foreach ($claims as $key => $claim) {
            $partner = $key === 'self' ? 'spouse' : 'self';

            if (isset($people[$partner])) {
                $excess = $config['spousal_share'] * $people[$partner]['benefit'] - $people[$key]['benefit'];

                // It starts once both have claimed, and is reduced by how
                // early in this person's life that is.
                if ($excess > 0) {
                    $start = max($claim['claim_month'], $claims[$partner]['claim_month']);

                    $claims[$key]['top_up'] = $excess * $this->spousalFactor($start - $people[$key]['birth_month'], $people[$key]['fra']);
                    $claims[$key]['top_up_month'] = $start;
                }
            }

            $claims[$key]['monthly'] = $claims[$key]['own'] + $claims[$key]['top_up'];
        }

        return $claims;
    }

    /**
     * What each person is paid in one month, before any cost-of-living rise.
     *
     * @param  array<string, array<string, mixed>>  $people
     * @param  array<string, array<string, mixed>>  $claims
     * @return array<string, float>
     */
    private function monthly(int $month, array $people, array $claims): array
    {
        $year = intdiv($month, 12);
        $paid = [];

        foreach ($people as $key => $person) {
            if ($year > $person['death_year'] || $month < $claims[$key]['claim_month']) {
                $paid[$key] = 0.0;

                continue;
            }

            $paid[$key] = $claims[$key]['own'] + ($claims[$key]['top_up_month'] !== null && $month >= $claims[$key]['top_up_month'] ? $claims[$key]['top_up'] : 0.0);
        }

        // A survivor keeps the larger of the two benefits: their own, or
        // what the one who died was drawing (or would have drawn).
        foreach ($people as $key => $person) {
            $partner = $key === 'self' ? 'spouse' : 'self';

            if (isset($people[$partner]) && $year > $people[$partner]['death_year'] && $year <= $person['death_year'] && $month >= $claims[$key]['claim_month']) {
                $paid[$key] = max($paid[$key], $claims[$partner]['own']);
            }
        }

        return $paid;
    }

    /**
     * How much of a year's benefit is taxable income, by the
     * provisional-income test: other income plus half the benefit, against
     * two thresholds.
     *
     * @param  array{0: int|float, 1: int|float}  $thresholds
     */
    public function taxable(float $benefit, float $otherIncome, array $thresholds): float
    {
        if ($benefit <= 0) {
            return 0.0;
        }

        [$first, $second] = $thresholds;
        $provisional = $otherIncome + $benefit / 2;

        if ($provisional <= $first) {
            return 0.0;
        }

        if ($provisional <= $second) {
            return min($benefit / 2, ($provisional - $first) / 2);
        }

        return min($benefit * 0.85, ($provisional - $second) * 0.85 + min($benefit / 2, ($second - $first) / 2));
    }

    /**
     * The household's taxed income apart from Social Security in each year
     * of the plan, as entered, in that year's dollars.
     *
     * @return array<int, float>
     */
    private function otherIncome(User $user, Profile $profile): array
    {
        $rows = $this->scenarios->rows($this->fleet->flows($user), collect(), $profile)
            ->where('direction', 'income')
            ->where('category', '!=', 'social_security')
            ->whereNotNull('taxation');

        $years = [];

        foreach ($rows as $row) {
            foreach ($row['series'] as $point) {
                $years[$point['year']] = ($years[$point['year']] ?? 0.0) + $point['amount'] * $row['taxable_share'];
            }
        }

        return $years;
    }

    /**
     * What the fleet's investable money is expected to earn: the default for
     * what a benefit taken early could have been invested at.
     */
    private function growthRate(User $user): float
    {
        $investable = $this->fleet->leaves($this->fleet->holdings($user))->filter->is_investable;
        $invested = (float) $investable->sum->value;

        if ($invested > 0) {
            return round($investable->sum(fn (Holding $holding): float => $holding->value * $holding->expected_rate) / $invested, 1);
        }

        return (float) config('finance.defaults.market_return');
    }

    /**
     * Each strategy with the age at which it overtakes the one that pays
     * soonest: the year its running total first catches up and stays ahead.
     * Null for that strategy itself, and for one that never does.
     *
     * @param  Collection<int, array<string, mixed>>  $strategies
     * @return Collection<int, array<string, mixed>>
     */
    private function withBreakevens(Collection $strategies): Collection
    {
        // Soonest to pay; among those, whichever has paid most by the end
        // of its first year.
        $earliest = $strategies->whereNotNull('summary.first_year')->sortBy([['summary.first_year', 'asc'], ['id', 'asc']])->first();

        return $strategies->map(function (array $strategy) use ($earliest): array {
            $breakeven = null;

            if ($earliest !== null && $strategy['id'] !== $earliest['id']) {
                $baseline = array_column($earliest['rows'], 'cumulative', 'year');

                foreach (array_reverse($strategy['rows']) as $row) {
                    if ($row['cumulative'] < ($baseline[$row['year']] ?? 0.0)) {
                        break;
                    }

                    $breakeven = $row['age'];
                }

                // Ahead from the very first year is not a crossing.
                if ($breakeven === ($strategy['rows'][0]['age'] ?? null)) {
                    $breakeven = null;
                }
            }

            return [...$strategy, 'breakeven' => ['age' => $breakeven, 'against' => $earliest !== null && $strategy['id'] !== $earliest['id'] ? $earliest['name'] : null]];
        });
    }
}
