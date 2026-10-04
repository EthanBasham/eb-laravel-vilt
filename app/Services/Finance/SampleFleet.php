<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use App\Models\Finance\Actual;
use App\Models\Finance\Armada;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Flow;
use App\Models\Finance\Goal;
use App\Models\Finance\Holding;
use App\Models\Finance\MonteCarloRun;
use App\Models\Finance\Profile;
use App\Models\Finance\Scenario;
use App\Models\Finance\Snapshot;
use App\Models\Finance\SocialSecurityStrategy;
use App\Models\Finance\Transfer;
use App\Models\Finance\WithdrawalStrategy;
use App\Models\User;

/**
 * A made-up household, so every tool has something to chew on before anyone
 * has typed in a real fleet.
 *
 * Written to exercise the whole model rather than to be typical: accounts
 * with and without positions, a retirement account that holds two accounts
 * of its own, four armadas, a household expense itemised into nine lines,
 * income and bills routed through checking with two transfers moving what is
 * left, a rental with its own income and expenses and a
 * mortgage secured against it, every pay frequency, a pension and Social
 * Security that have not started yet, and a traditional balance big enough
 * for the Retirement Strategizer to have an opinion about.
 */
class SampleFleet
{
    public function __construct(private RealityBoard $reality) {}

    /**
     * Whether the user has anything in the sub-project at all.
     */
    public function isEmptyFor(User $user): bool
    {
        return ! Holding::query()->onlyOwnedBy($user)->exists()
            && ! Flow::query()->onlyOwnedBy($user)->exists();
    }

    public function load(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $birthYear = now()->year - 52;

            Profile::query()->updateOrCreate(['user_id' => $user->id], [
                'birth_date' => "{$birthYear}-05-14",
                'filing_status' => 'married_joint',
                'retirement_age' => 62,
                'life_expectancy' => 92,
                'inflation_rate' => 2.5,
                // What the Social Security tab works from: each person's
                // benefit at full retirement age, and a spouse two years
                // younger.
                'ss_monthly_benefit' => 2950,
                'spouse_birth_date' => ($birthYear + 2).'-09-22',
                'spouse_ss_monthly_benefit' => 1150,
            ]);

            // The fleet in parts. Whatever is hung off a holding follows it,
            // so only the holdings and the standalone flows are assigned.
            $armada = fn (string $name, string $description): int => Armada::query()->create(['user_id' => $user->id, 'name' => $name, 'description' => $description])->id;

            $foundational = $armada('Foundational', 'The household: where we live, what we drive, the paycheque and the bills.');
            $realEstate = $armada('Real estate', 'Rental property and everything it costs.');
            $retirement = $armada('Retirement', 'The accounts, and the income that starts when work stops.');
            $businessArmada = $armada('Business', 'The consultancy.');

            $holding = fn (string $type, string $name, float $balance, array $more = []): Holding => Holding::query()->create([
                'user_id' => $user->id,
                'side' => config("finance.holding_types.{$type}.side"),
                'type' => $type,
                'name' => $name,
                'balance' => $balance,
                'annual_rate' => config("finance.holding_types.{$type}.rate"),
                ...$more,
            ]);

            $flow = fn (string $direction, string $category, string $name, float $amount, string $frequency, array $more = []): Flow => Flow::query()->create([
                'user_id' => $user->id,
                'direction' => $direction,
                'category' => $category,
                'name' => $name,
                'amount' => $amount,
                'frequency' => $frequency,
                'taxation' => config("finance.flow_categories.{$direction}.{$category}.taxation"),
                'is_essential' => (bool) config("finance.flow_categories.expense.{$category}.essential", false),
                ...$more,
            ]);

            // Cash
            $checking = $holding('cash', 'Everyday checking', 8200, ['institution' => 'First Prairie Bank', 'armada_id' => $foundational]);
            $savings = $holding('savings', 'Emergency fund', 45000, ['institution' => 'Harbor Online', 'annual_rate' => 4.1, 'monthly_contribution' => 400, 'armada_id' => $foundational]);

            // Investments, two of them itemised
            $traditional = $holding('retirement', 'Work 401(k)', 0, ['plan_type' => '401k', 'tax_type' => 'traditional', 'institution' => 'Meridian Retirement', 'monthly_contribution' => 1450, 'armada_id' => $retirement]);
            $traditional->positions()->createMany([
                ['name' => 'Target Date 2040', 'symbol' => 'TD2040', 'asset_class' => 'mutual_fund', 'value' => 410000, 'expected_return' => 6.4, 'expense_ratio' => 0.35],
                ['name' => 'S&P 500 Index', 'symbol' => 'SPIDX', 'asset_class' => 'index_fund', 'value' => 215000, 'expected_return' => 7.2, 'expense_ratio' => 0.02],
                ['name' => 'Stable Value', 'symbol' => null, 'asset_class' => 'bond', 'value' => 55000, 'expected_return' => 3.4, 'expense_ratio' => 0.25],
            ]);

            // A compound account: one Roth IRA, split across two custodians.
            // Its own balance stays at zero — it is worth what is inside it.
            $roth = $holding('retirement', 'Roth IRA', 0, ['plan_type' => 'ira', 'tax_type' => 'roth', 'armada_id' => $retirement]);
            $holding('brokerage', 'Index funds', 71000, ['parent_id' => $roth->id, 'institution' => 'Northfield', 'annual_rate' => 7.2, 'monthly_contribution' => 480]);
            $holding('brokerage', 'Stock picks', 25000, ['parent_id' => $roth->id, 'institution' => 'Tidewater', 'annual_rate' => 8.5, 'monthly_contribution' => 100]);
            $holding('hsa', 'HSA', 31500, ['institution' => 'HealthBridge', 'monthly_contribution' => 350, 'armada_id' => $retirement]);

            $brokerage = $holding('brokerage', 'Joint brokerage', 0, ['institution' => 'Northfield', 'monthly_contribution' => 500, 'armada_id' => $retirement]);
            $brokerage->positions()->createMany([
                ['name' => 'Total Market ETF', 'symbol' => 'TMKT', 'asset_class' => 'etf', 'value' => 82000, 'expected_return' => 7.0, 'expense_ratio' => 0.03],
                ['name' => 'International ETF', 'symbol' => 'INTL', 'asset_class' => 'etf', 'value' => 28000, 'expected_return' => 6.2, 'expense_ratio' => 0.07],
                ['name' => 'Acme Industrial', 'symbol' => 'ACME', 'asset_class' => 'stock', 'value' => 19500, 'expected_return' => 8.5, 'expense_ratio' => 0],
                ['name' => 'Muni Bond Fund', 'symbol' => 'MUNI', 'asset_class' => 'bond', 'value' => 14000, 'expected_return' => 3.6, 'expense_ratio' => 0.12],
            ]);

            $holding('crypto', 'Cold wallet', 12400, ['armada_id' => $retirement]);

            // Property, each with the debt secured against it
            $home = $holding('real_estate', 'Family home', 525000, ['armada_id' => $foundational]);
            $holding('mortgage', 'Home mortgage', 284000, ['annual_rate' => 3.25, 'monthly_contribution' => 1680, 'secured_by_id' => $home->id, 'armada_id' => $foundational]);

            $rental = $holding('real_estate', 'Maple St duplex', 345000, ['annual_rate' => 3.8, 'armada_id' => $realEstate]);
            $holding('mortgage', 'Duplex mortgage', 212000, ['annual_rate' => 6.1, 'monthly_contribution' => 1495, 'secured_by_id' => $rental->id, 'armada_id' => $realEstate]);

            $flow('income', 'rental', 'Duplex rent', 3150, 'monthly', ['holding_id' => $rental->id, 'annual_growth_rate' => 3, 'account_id' => $checking->id]);
            $flow('expense', 'management', 'Property manager', 252, 'monthly', ['holding_id' => $rental->id]);
            $flow('expense', 'insurance', 'Landlord policy', 1560, 'annual', ['holding_id' => $rental->id]);
            $flow('expense', 'taxes', 'Duplex property tax', 4150, 'annual', ['holding_id' => $rental->id]);
            $flow('expense', 'maintenance', 'Repairs reserve', 850, 'quarterly', ['holding_id' => $rental->id]);
            $flow('expense', 'maintenance', 'HVAC service contract', 480, 'annual', ['holding_id' => $rental->id]);
            $flow('expense', 'debt', 'Duplex mortgage payment', 1495, 'monthly', ['holding_id' => $rental->id]);

            $car = $holding('vehicle', 'Outback', 24500, ['armada_id' => $foundational]);
            $holding('auto_loan', 'Car loan', 13800, ['annual_rate' => 6.9, 'monthly_contribution' => 410, 'secured_by_id' => $car->id, 'armada_id' => $foundational]);
            $card = $holding('credit_card', 'Rewards card', 3250, ['annual_rate' => 23.9, 'monthly_contribution' => 250, 'armada_id' => $foundational]);

            // A side business, with its own money in and out
            $business = $holding('business', 'Basalt Consulting LLC', 60000, ['armada_id' => $businessArmada]);
            $flow('income', 'business', 'Retainer clients', 6500, 'quarterly', ['holding_id' => $business->id]);
            $flow('expense', 'contractors', 'Subcontracted design', 1500, 'quarterly', ['holding_id' => $business->id]);

            // Household income, one of each shape
            $flow('income', 'salary', 'Salary', 4650, 'biweekly', ['annual_growth_rate' => 3, 'armada_id' => $foundational, 'account_id' => $checking->id]);
            $flow('income', 'contract', 'Freelance hours', 95, 'hourly', ['hours_per_week' => 5, 'armada_id' => $businessArmada]);
            $flow('income', 'contract', 'Conference workshop', 3200, 'once', ['starts_on' => now()->addMonths(2)->startOfMonth()->toDateString(), 'armada_id' => $businessArmada]);
            $flow('income', 'pension', 'County pension', 1400, 'monthly', ['starts_on' => ($birthYear + 65).'-06-01', 'annual_growth_rate' => 1.5, 'armada_id' => $retirement]);
            $flow('income', 'social_security', 'Social Security', 2950, 'monthly', ['starts_on' => ($birthYear + 67).'-06-01', 'annual_growth_rate' => 2.5, 'taxed_portion' => 85, 'armada_id' => $retirement]);

            // Household spending. The debts and the things owned are lines
            // of their own; the day-to-day is one "Household expenses" flow,
            // itemised, which every page then carries as a single figure.
            $own = ['armada_id' => $foundational, 'account_id' => $checking->id];

            $flow('expense', 'housing', 'Home mortgage payment', 1680, 'monthly', $own);
            $flow('expense', 'taxes', 'Home property tax', 6300, 'annual', $own);
            $flow('expense', 'insurance', 'Home & auto insurance', 2850, 'annual', $own);
            $flow('expense', 'debt', 'Car payment', 410, 'monthly', $own);
            $flow('expense', 'debt', 'Credit card payment', 250, 'monthly', $own);

            // Its own amount is the estimate it would stand at with no items.
            $household = $flow('expense', 'household', 'Household expenses', 2400, 'monthly', ['annual_growth_rate' => 2.5, ...$own]);
            $item = fn (string $category, string $name, float $amount, string $frequency): Flow => $flow('expense', $category, $name, $amount, $frequency, ['parent_id' => $household->id, 'annual_growth_rate' => 2.5]);

            $item('healthcare', 'Health premiums', 430, 'monthly');
            $item('utilities', 'Utilities', 345, 'monthly');
            $item('utilities', 'Phones & internet', 195, 'monthly');
            $item('food', 'Groceries', 240, 'weekly');
            $item('transport', 'Fuel & upkeep', 260, 'monthly');
            $item('subscriptions', 'Netflix', 22.99, 'monthly');
            $item('subscriptions', 'Music & cloud storage', 26.98, 'monthly');
            $item('entertainment', 'Eating out', 380, 'monthly');
            $item('travel', 'Summer trip', 4800, 'annual');

            // What is left over is put to work: keep a cushion in checking,
            // overpay the card until it is gone, send the rest to savings.
            Transfer::query()->create(['user_id' => $user->id, 'name' => 'Overpay the rewards card', 'kind' => 'fixed', 'from_holding_id' => $checking->id, 'to_holding_id' => $card->id, 'amount' => 300, 'sort_order' => 1]);
            Transfer::query()->create(['user_id' => $user->id, 'name' => 'Sweep checking into savings', 'kind' => 'sweep', 'from_holding_id' => $checking->id, 'to_holding_id' => $savings->id, 'keep_balance' => 6000, 'sort_order' => 2]);

            Goal::query()->create(['user_id' => $user->id, 'name' => 'Replace the car', 'target_amount' => 38000, 'saved_amount' => 9000, 'target_date' => now()->addMonths(30)->toDateString()]);
            Goal::query()->create(['user_id' => $user->id, 'name' => 'Kitchen remodel', 'target_amount' => 55000, 'saved_amount' => 6500, 'target_date' => now()->addMonths(48)->toDateString()]);
            Goal::query()->create(['user_id' => $user->id, 'name' => 'Six-month cushion', 'target_amount' => 60000, 'saved_amount' => 45000, 'target_date' => now()->addMonths(18)->toDateString()]);

            $this->backfillHistory($user);
        });
    }

    /**
     * Removes everything the user has in the sub-project, profile included.
     */
    public function clear(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Holdings take their positions and flows with them, and flows
            // their actuals, by cascade.
            Actual::query()->onlyOwnedBy($user)->delete();
            Flow::query()->onlyOwnedBy($user)->delete();
            Holding::query()->onlyOwnedBy($user)->delete();
            Goal::query()->onlyOwnedBy($user)->delete();
            // After the holdings: a transfer goes with either of its ends,
            // and what is left of the rest has nothing pointing at it.
            Transfer::query()->onlyOwnedBy($user)->delete();
            Armada::query()->onlyOwnedBy($user)->delete();
            SocialSecurityStrategy::query()->onlyOwnedBy($user)->delete();
            WithdrawalStrategy::query()->onlyOwnedBy($user)->delete();
            ConversionStrategy::query()->onlyOwnedBy($user)->delete();
            MonteCarloRun::query()->onlyOwnedBy($user)->delete();
            Scenario::query()->onlyOwnedBy($user)->delete();
            Snapshot::query()->onlyOwnedBy($user)->delete();
            Profile::query()->onlyOwnedBy($user)->delete();
        });
    }

    /**
     * Three earlier snapshots and a month of budget actuals, so Projected vs
     * Reality opens with a line on it rather than a single dot.
     *
     * The oldest snapshot is the baseline: its projection is today's, scaled
     * down to where the fleet "was". The later ones wander either side of it
     * by fixed amounts — fixed rather than random so the page (and anything
     * asserting on it) is the same on every load.
     */
    private function backfillHistory(User $user): void
    {
        $today = $this->reality->capture($user, 'Sample fleet loaded');
        $drift = [9 => 0.925, 6 => 0.958, 3 => 0.971];
        $baselineScale = $drift[9];

        foreach ($drift as $monthsAgo => $scale) {
            Snapshot::query()->create([
                'user_id' => $user->id,
                'taken_on' => now()->subMonthsNoOverflow($monthsAgo)->toDateString(),
                'assets' => round($today->assets * $scale),
                'liabilities' => round($today->liabilities * (2 - $scale)),
                'net_worth' => round($today->assets * $scale - $today->liabilities * (2 - $scale)),
                'projection' => array_map(fn (float|int $value): float => round($value * $baselineScale), $today->projection),
                'note' => $monthsAgo === 9 ? 'Baseline' : null,
            ]);
        }

        $actuals = ['Groceries' => 1118.40, 'Eating out' => 462.15, 'Utilities' => 318.72, 'Fuel & upkeep' => 301.00, 'Netflix' => 22.99, 'Salary' => 10075.00, 'Duplex rent' => 3150.00];

        Flow::query()->onlyOwnedBy($user)->whereIn('name', array_keys($actuals))->get()->each(fn (Flow $flow) => Actual::query()->create([
            'user_id' => $user->id,
            'flow_id' => $flow->id,
            'month' => now()->startOfMonth()->toDateString(),
            'amount' => $actuals[$flow->name],
        ]));
    }
}
