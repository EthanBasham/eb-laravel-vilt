<?php

namespace App\Services\Finance;

/**
 * Loan arithmetic, shared by the mortgage and payoff calculators, the real
 * estate comparator, the goal planner's "finance it" strategy and the fleet
 * projector's liabilities.
 *
 * Rates are annual percentages, compounded monthly — how a US loan is quoted.
 */
class Amortization
{
    /**
     * The level monthly payment that clears a loan in exactly $months.
     */
    public static function payment(float $principal, float $annualRate, int $months): float
    {
        if ($principal <= 0 || $months <= 0) {
            return 0.0;
        }

        $monthlyRate = $annualRate / 100 / 12;

        if ($monthlyRate == 0.0) {
            return $principal / $months;
        }

        return $principal * $monthlyRate / (1 - (1 + $monthlyRate) ** -$months);
    }

    /**
     * Runs a balance down under a fixed monthly payment.
     *
     * `balances` is the balance at the end of each month, with the opening
     * balance at index 0. A payment that does not cover the interest never
     * clears the loan; that comes back as `paid_off: false` after $maxMonths
     * rather than as an endless loop.
     *
     * @return array{months: int, paid_off: bool, total_interest: float, total_paid: float, balances: list<float>, years: list<array{year: int, interest: float, principal: float, balance: float}>}
     */
    public static function schedule(float $principal, float $annualRate, float $payment, int $maxMonths = 600): array
    {
        $monthlyRate = $annualRate / 100 / 12;
        $balance = max(0.0, $principal);
        $balances = [$balance];
        $years = [];
        $totalInterest = 0.0;
        $totalPaid = 0.0;
        $month = 0;
        $yearInterest = 0.0;
        $yearPrincipal = 0.0;

        while ($balance > 0.005 && $month < $maxMonths) {
            $month++;

            $interest = $balance * $monthlyRate;
            $paid = min($payment, $balance + $interest);
            $balance = max(0.0, $balance + $interest - $paid);

            $totalInterest += $interest;
            $totalPaid += $paid;
            $yearInterest += $interest;
            $yearPrincipal += $paid - $interest;
            $balances[] = $balance;

            if ($month % 12 === 0 || $balance <= 0.005) {
                $years[] = [
                    'year' => (int) ceil($month / 12),
                    'interest' => round($yearInterest, 2),
                    'principal' => round($yearPrincipal, 2),
                    'balance' => round($balance, 2),
                ];
                $yearInterest = 0.0;
                $yearPrincipal = 0.0;
            }
        }

        return [
            'months' => $month,
            'paid_off' => $balance <= 0.005,
            'total_interest' => round($totalInterest, 2),
            'total_paid' => round($totalPaid, 2),
            'balances' => $balances,
            'years' => $years,
        ];
    }

    /**
     * The balance left on a loan after $monthsPaid level payments.
     */
    public static function balanceAfter(float $principal, float $annualRate, int $termMonths, int $monthsPaid): float
    {
        $monthlyRate = $annualRate / 100 / 12;
        $monthsPaid = min($monthsPaid, $termMonths);

        if ($monthlyRate == 0.0) {
            return max(0.0, $principal * (1 - $monthsPaid / max(1, $termMonths)));
        }

        $payment = static::payment($principal, $annualRate, $termMonths);

        return max(0.0, $principal * (1 + $monthlyRate) ** $monthsPaid - $payment * ((1 + $monthlyRate) ** $monthsPaid - 1) / $monthlyRate);
    }

    /**
     * What a balance grows to with a deposit at the end of each month.
     *
     * The annual rate is taken as an effective yield — a 7% return means the
     * money is 7% bigger a year on — so the monthly rate is its twelfth root,
     * not a twelfth of it.
     */
    public static function futureValue(float $present, float $monthlyDeposit, float $annualRate, int $months): float
    {
        $monthlyRate = static::monthlyGrowth($annualRate);

        if ($monthlyRate == 0.0) {
            return $present + $monthlyDeposit * $months;
        }

        $growth = (1 + $monthlyRate) ** $months;

        return $present * $growth + $monthlyDeposit * ($growth - 1) / $monthlyRate;
    }

    /**
     * The monthly deposit that grows $present into $target over $months.
     * Zero when the money already there gets there by itself.
     */
    public static function depositFor(float $target, float $present, float $annualRate, int $months): float
    {
        if ($months <= 0) {
            return max(0.0, $target - $present);
        }

        $monthlyRate = static::monthlyGrowth($annualRate);

        if ($monthlyRate == 0.0) {
            return max(0.0, ($target - $present) / $months);
        }

        $growth = (1 + $monthlyRate) ** $months;

        return max(0.0, ($target - $present * $growth) * $monthlyRate / ($growth - 1));
    }

    /** An effective annual percentage as a monthly growth factor less one. */
    public static function monthlyGrowth(float $annualRate): float
    {
        return (1 + max(-99.0, $annualRate) / 100) ** (1 / 12) - 1;
    }
}
