<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use App\Models\Finance\Holding;
use App\Models\User;

/**
 * The Real Estate Comparator: up to three properties, side by side, on the
 * measures an investor screens with — cap rate, cash-on-cash, DSCR — and on
 * what each returns over a holding period.
 */
class RealEstateAnalyzer
{
    public const MAX_PROPERTIES = 3;

    /**
     * Field => [default, min, max]. The order is the order the form prints.
     *
     * @var array<string, array{0: float|int, 1: float|int, 2: float|int}>
     */
    public const FIELDS = [
        'price' => [350000, 0, 50_000_000],
        'down_pct' => [25, 0, 100],
        'rate' => [7.0, 0, 25],
        'term_years' => [30, 1, 40],
        'closing_pct' => [3, 0, 15],
        'rent' => [2600, 0, 1_000_000],
        'vacancy_pct' => [5, 0, 100],
        'tax_annual' => [4200, 0, 1_000_000],
        'insurance_annual' => [1800, 0, 1_000_000],
        'maintenance_pct' => [1, 0, 20],
        'management_pct' => [8, 0, 50],
        'hoa_monthly' => [0, 0, 50_000],
        'appreciation_pct' => [3.5, -10, 20],
        'rent_growth_pct' => [3, -10, 20],
    ];

    public function __construct(private Fleet $fleet) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function for(User $user, array $input): array
    {
        $options = ToolInput::numbers($input, [
            'hold_years' => [10, 1, 40],
            'selling_pct' => [6, 0, 15],
        ]);

        $submitted = collect(is_array($input['properties'] ?? null) ? $input['properties'] : [])
            ->filter(fn (mixed $property): bool => is_array($property))
            ->take(self::MAX_PROPERTIES)
            ->values();

        $properties = $submitted->isNotEmpty() ? $submitted : $this->starters($user);

        $analyses = $properties->map(fn (array $property, int $index): array => $this->analyze($property, $options, $index))->all();

        return [
            'options' => $options,
            'properties' => $analyses,
            'best' => collect($analyses)->sortByDesc(fn (array $analysis): float => $analysis['irr'] ?? -INF)->keys()->first(),
            'max_properties' => self::MAX_PROPERTIES,
        ];
    }

    /**
     * @param  array<string, mixed>  $property
     * @param  array<string, float>  $options
     * @return array<string, mixed>
     */
    public function analyze(array $property, array $options, int $index = 0): array
    {
        $in = ToolInput::numbers($property, self::FIELDS);
        $name = is_string($property['name'] ?? null) && trim($property['name']) !== ''
            ? mb_substr(trim($property['name']), 0, 60)
            : 'Property '.chr(65 + $index);

        $down = $in['price'] * $in['down_pct'] / 100;
        $loan = $in['price'] - $down;
        $cashIn = $down + $in['price'] * $in['closing_pct'] / 100;
        $termMonths = (int) $in['term_years'] * 12;
        $payment = Amortization::payment($loan, $in['rate'], $termMonths);
        $holdYears = (int) $options['hold_years'];

        $years = [];
        $cashflows = [-$cashIn];
        $totalCashflow = 0.0;

        for ($year = 1; $year <= $holdYears; $year++) {
            $rentFactor = (1 + $in['rent_growth_pct'] / 100) ** ($year - 1);
            $value = $in['price'] * (1 + $in['appreciation_pct'] / 100) ** $year;

            $grossRent = $in['rent'] * 12 * $rentFactor;
            $effectiveRent = $grossRent * (1 - $in['vacancy_pct'] / 100);
            // Costs that track the property (tax, insurance, upkeep, HOA) rise
            // with rents; management is a cut of the rent actually collected.
            $operating = ($in['tax_annual'] + $in['insurance_annual'] + $in['price'] * $in['maintenance_pct'] / 100 + $in['hoa_monthly'] * 12) * $rentFactor
                + $effectiveRent * $in['management_pct'] / 100;
            $noi = $effectiveRent - $operating;
            $debtService = $year * 12 <= $termMonths ? $payment * 12 : 0.0;
            $cashflow = $noi - $debtService;
            $balance = Amortization::balanceAfter($loan, $in['rate'], $termMonths, $year * 12);

            $totalCashflow += $cashflow;
            $cashflows[] = $cashflow;

            $years[] = [
                'year' => $year,
                'noi' => round($noi),
                'cashflow' => round($cashflow),
                'value' => round($value),
                'balance' => round($balance),
                'equity' => round($value - $balance),
            ];
        }

        $first = $years[0];
        $last = $years[$holdYears - 1];
        $saleProceeds = $last['value'] * (1 - $options['selling_pct'] / 100) - $last['balance'];
        $cashflows[$holdYears] += $saleProceeds;
        $profit = $totalCashflow + $saleProceeds - $cashIn;
        $debtService = $payment * 12;

        return [
            'name' => $name,
            'inputs' => $in,
            'loan' => round($loan),
            'cash_in' => round($cashIn),
            'payment' => round($payment, 2),
            'noi' => $first['noi'],
            'monthly_cashflow' => round($first['cashflow'] / 12),
            'cap_rate' => $in['price'] > 0 ? round($first['noi'] / $in['price'] * 100, 2) : null,
            'cash_on_cash' => $cashIn > 0 ? round($first['cashflow'] / $cashIn * 100, 2) : null,
            'dscr' => $debtService > 0 ? round($first['noi'] / $debtService, 2) : null,
            'equity_at_exit' => $last['equity'],
            'sale_proceeds' => round($saleProceeds),
            'total_cashflow' => round($totalCashflow),
            'profit' => round($profit),
            'equity_multiple' => $cashIn > 0 ? round(($totalCashflow + $saleProceeds) / $cashIn, 2) : null,
            'irr' => $this->irr($cashflows),
            'years' => $years,
        ];
    }

    /**
     * The annual rate that discounts a run of yearly cashflows to zero, as a
     * percentage, by bisection. Null when there is no sign change to find —
     * all money in, or none.
     *
     * @param  list<float>  $cashflows  Year 0 first.
     */
    public function irr(array $cashflows): ?float
    {
        $npv = fn (float $rate): float => array_sum(array_map(
            fn (float $cashflow, int $year): float => $cashflow / (1 + $rate) ** $year,
            $cashflows,
            array_keys($cashflows),
        ));

        $low = -0.95;
        $high = 2.0;

        if ($npv($low) * $npv($high) > 0) {
            return null;
        }

        for ($pass = 0; $pass < 80; $pass++) {
            $middle = ($low + $high) / 2;

            if ($npv($low) * $npv($middle) <= 0) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        return round(($low + $high) / 2 * 100, 2);
    }

    /**
     * What the form opens with: the rented-out real estate already in the
     * fleet (a home nobody pays rent on is not an investment to screen, and
     * would only be given a made-up rent), read as far as the fleet describes it, made up to two with a blank example so
     * there is always something to compare against.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function starters(User $user): Collection
    {
        $owned = Holding::query()->onlyOwnedBy($user)->where('type', 'real_estate')
            ->with(['positions', 'children.positions', 'flows.children', 'securedDebts.positions', 'securedDebts.children.positions'])
            ->whereHas('flows', fn ($flows) => $flows->where('direction', 'income'))
            ->inDefaultOrder()->limit(self::MAX_PROPERTIES)->get()
            ->map(function (Holding $holding): array {
                $price = $holding->value;
                $debt = (float) $holding->securedDebts->sum->value;
                $annual = fn (string $category): float => (float) $holding->flows->where('category', $category)->sum->annual_amount;
                $rent = (float) $holding->flows->where('direction', 'income')->sum->annual_amount;

                return array_filter([
                    'name' => $holding->name,
                    'price' => $price,
                    'down_pct' => $price > 0 ? round(max(0, 1 - $debt / $price) * 100, 1) : null,
                    'rate' => $holding->securedDebts->first()?->annual_rate,
                    'closing_pct' => 0,
                    'rent' => $rent > 0 ? round($rent / 12) : null,
                    'tax_annual' => $annual('taxes') ?: null,
                    'insurance_annual' => $annual('insurance') ?: null,
                    'maintenance_pct' => $price > 0 && $annual('maintenance') > 0 ? round($annual('maintenance') / $price * 100, 2) : null,
                    'management_pct' => $rent > 0 && $annual('management') > 0 ? round($annual('management') / $rent * 100, 1) : null,
                    'appreciation_pct' => $holding->expected_rate,
                ], fn (mixed $value): bool => $value !== null);
            });

        $examples = collect([
            ['name' => 'Example duplex', 'price' => 420000, 'rent' => 3400, 'tax_annual' => 5200, 'insurance_annual' => 2100],
            ['name' => 'Example condo', 'price' => 260000, 'rent' => 1950, 'tax_annual' => 2900, 'insurance_annual' => 900, 'hoa_monthly' => 280],
        ]);

        return $owned->concat($examples->take(max(0, 2 - $owned->count())))->values();
    }
}
