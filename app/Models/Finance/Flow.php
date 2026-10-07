<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonInterface;
use Database\Factories\Finance\FlowFactory;

/**
 * Money moving on a schedule: an income stream or an expense.
 *
 * A flow can be compound, as a holding can: a "Household expenses" flow with
 * items inside (`parent_id`) comes to their sum, in every year and every
 * month, and its own amount, frequency, rate and dates are ignored while it
 * has any. With none it is an ordinary flow, and its amount is the estimate.
 *
 * The amounts read the items, so load `children` before reading any of them
 * across a list — Fleet::flows() does.
 *
 * Three ids, three different questions. `holding_id` is what the flow belongs
 * to (the rent on a duplex belongs to the duplex). `account_id` is the asset
 * it is paid into or out of. `armada_id` is the armada it sails in when it
 * belongs to no holding; see `armada_key`.
 *
 * @property-read bool $is_compound
 * @property-read bool $is_itemized
 * @property-read ?int $armada_key
 * @property-read ?int $routed_account_id
 * @property-read bool $is_income
 * @property-read float $annual_amount
 * @property-read float $monthly_amount
 * @property-read float $current_monthly_amount
 * @property-read bool $is_earned
 * @property-read float $taxable_share
 * @property-read string $category_label
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'armada_id', 'holding_id', 'parent_id', 'account_id', 'direction', 'category', 'name', 'amount', 'frequency', 'hours_per_week', 'annual_growth_rate', 'taxation', 'taxed_portion', 'is_essential', 'starts_on', 'ends_on'])]
class Flow extends OwnedModel
{
    /** @use HasFactory<FlowFactory> */
    use HasFactory;

    protected $table = 'fin_flows';

    /**
     * An income is taxed whole unless a smaller portion is set.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'taxed_portion' => 100,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'hours_per_week' => 'float',
            'annual_growth_rate' => 'float',
            'taxed_portion' => 'float',
            'is_essential' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * What the flow comes to in a given calendar year.
     *
     * Three things move it off the plain run rate: the growth rate compounds
     * from this year forward, a start or end date inside the year keeps only
     * the months it covers, and earned income with no end date of its own
     * stops at `$retirementYear` — nobody types an end date on their salary.
     *
     * A one-time flow lands whole in the year of its start date (this year,
     * when it has none) and nowhere else.
     *
     * `$growthRate` stands in for the flow's own rate when a scenario has
     * given it a different one.
     *
     * `$restart` is a year a scenario set by hand and started the rate again
     * from: the years after it compound from that amount, taken as a whole
     * year's, instead of from today's. Dates and retirement apply as ever.
     *
     * @param  array{year: int, amount: float}|null  $restart
     */
    public function amountInYear(int $year, ?int $retirementYear = null, ?float $growthRate = null, ?array $restart = null): float
    {
        if ($this->is_compound) {
            return (float) $this->children->sum(fn (self $item): float => $item->amountInYear($year, $retirementYear, $growthRate));
        }

        if ($this->frequency === 'once') {
            if (($this->starts_on?->year ?? now()->year) === $year) {
                return $this->amount;
            }

            return 0.0;
        }

        if ($retirementYear !== null && $this->is_earned && $this->ends_on === null && $year >= $retirementYear) {
            return 0.0;
        }

        $firstMonth = match (true) {
            $this->starts_on === null || $this->starts_on->year < $year => 1,
            $this->starts_on->year > $year => 13,
            default => $this->starts_on->month,
        };

        $lastMonth = match (true) {
            $this->ends_on === null || $this->ends_on->year > $year => 12,
            $this->ends_on->year < $year => 0,
            default => $this->ends_on->month,
        };

        $monthsActive = max(0, $lastMonth - $firstMonth + 1);
        $growth = 1 + ($growthRate ?? $this->annual_growth_rate) / 100;

        if ($restart !== null && $year > $restart['year']) {
            return $restart['amount'] * $growth ** ($year - $restart['year']) * $monthsActive / 12;
        }

        $yearsOut = max(0, $year - now()->year);

        return $this->annual_amount * $growth ** $yearsOut * $monthsActive / 12;
    }

    /**
     * What the budget plans for this flow in one month: its monthly run rate
     * while the flow is active — grown at its own rate for a month in a later
     * year, as amountInYear() grows the year — the whole amount in the month
     * a one-time flow lands, and nothing otherwise.
     */
    public function plannedFor(CarbonInterface $month): float
    {
        if ($this->is_compound) {
            return (float) $this->children->sum(fn (self $item): float => $item->plannedFor($month));
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        if ($this->frequency === 'once') {
            if (($this->starts_on ?? now())->isSameMonth($start)) {
                return $this->amount;
            }

            return 0.0;
        }

        if ($this->starts_on?->greaterThan($end) || $this->ends_on?->lessThan($start)) {
            return 0.0;
        }

        return $this->monthly_amount * (1 + $this->annual_growth_rate / 100) ** max(0, $month->year - now()->year);
    }

    protected function isIncome(): Attribute
    {
        return Attribute::get(fn (): bool => $this->direction === 'income');
    }

    /** Whether other flows sit inside this one. */
    protected function isCompound(): Attribute
    {
        return Attribute::get(fn (): bool => $this->children->isNotEmpty());
    }

    /** Whether its category lets it hold items — "Household expenses" does. */
    protected function isItemized(): Attribute
    {
        return Attribute::get(fn (): bool => (bool) config("finance.flow_categories.{$this->direction}.{$this->category}.itemized", false));
    }

    /**
     * The armada the flow sails in: its holding's when it belongs to one, the
     * outer flow's when it is an item, its own otherwise.
     */
    protected function armadaKey(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->holding) {
                return $this->holding->armada_key;
            }

            if ($this->parent) {
                return $this->parent->armada_key;
            }

            return $this->armada_id;
        });
    }

    /**
     * The asset the flow is paid into or out of, if any. An item inside
     * another is paid from wherever the outer flow is unless it names its own.
     */
    protected function routedAccountId(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->account_id ?? $this->parent?->account_id);
    }

    /**
     * The run rate: what the flow comes to over a full year at today's amount,
     * whatever its dates. Zero for a one-time flow, which has no run rate.
     */
    protected function annualAmount(): Attribute
    {
        return Attribute::get(function (): float {
            if ($this->is_compound) {
                return (float) $this->children->sum->annual_amount;
            }

            $perYear = (float) config("finance.frequencies.{$this->frequency}.per_year", 0);

            if ($this->frequency === 'hourly') {
                return $this->amount * ($this->hours_per_week ?? 0) * $perYear;
            }

            return $this->amount * $perYear;
        });
    }

    protected function monthlyAmount(): Attribute
    {
        return Attribute::get(fn (): float => $this->annual_amount / 12);
    }

    /**
     * What the flow is contributing to this month's run rate: its monthly
     * amount if it is running now, nothing if it has not started, has ended,
     * or is a one-time amount.
     *
     * `monthly_amount` answers "what is this flow worth a month"; this answers
     * "what is coming in a month today". A pension that starts in 2039 has the
     * first and not the second, and totalling the first is how a household
     * ends up credited with income it will not see for thirteen years.
     */
    protected function currentMonthlyAmount(): Attribute
    {
        return Attribute::get(function (): float {
            if ($this->is_compound) {
                return (float) $this->children->sum->current_monthly_amount;
            }

            if ($this->frequency === 'once') {
                return 0.0;
            }

            return $this->plannedFor(now());
        });
    }

    /** Whether this is income from working, which stops at retirement. */
    protected function isEarned(): Attribute
    {
        return Attribute::get(fn (): bool => (bool) config("finance.flow_categories.income.{$this->category}.earned", false));
    }

    /**
     * The share of the flow that is taxed at all: none of an expense or of an
     * income with no tax treatment, otherwise its taxed portion.
     */
    protected function taxableShare(): Attribute
    {
        return Attribute::get(function (): float {
            if ($this->is_income && $this->taxation !== null) {
                return $this->taxed_portion / 100;
            }

            return 0.0;
        });
    }

    protected function categoryLabel(): Attribute
    {
        return Attribute::get(fn (): string => config("finance.flow_categories.{$this->direction}.{$this->category}.label", $this->category));
    }

    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'armada_id' => $this->armada_key,
            'holding_id' => $this->holding_id,
            'holding_name' => $this->holding?->name,
            'parent_id' => $this->parent_id,
            'account_id' => $this->account_id,
            'account_name' => $this->account?->name,
            'is_itemized' => $this->is_itemized,
            'items_count' => $this->children->count(),
            'direction' => $this->direction,
            'category' => $this->category,
            'category_label' => $this->category_label,
            'name' => $this->name,
            'amount' => $this->amount,
            'frequency' => $this->frequency,
            'frequency_label' => config("finance.frequencies.{$this->frequency}.label", $this->frequency),
            'hours_per_week' => $this->hours_per_week,
            'annual_growth_rate' => $this->annual_growth_rate,
            'taxation' => $this->taxation,
            'taxation_label' => config("finance.flow_taxations.{$this->taxation}.label"),
            'taxed_portion' => $this->taxed_portion,
            'is_essential' => $this->is_essential,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'monthly_amount' => round($this->monthly_amount, 2),
            'annual_amount' => round($this->annual_amount, 2),
            'is_running' => $this->is_compound || $this->frequency === 'once' || $this->current_monthly_amount > 0 || $this->amount == 0,
            // Not running because it has yet to begin, rather than because
            // it is over. Decided here, in app time: a browser's idea of
            // today is UTC's by the evening.
            'starts_later' => (bool) $this->starts_on?->isAfter(today()),
        ]);
    }

    // Scopes

    public function scopeOnlyIncome(Builder $query): Builder
    {
        return $query->where('direction', 'income');
    }

    public function scopeOnlyExpenses(Builder $query): Builder
    {
        return $query->where('direction', 'expense');
    }

    /** Flows that stand on their own, not items inside another. */
    public function scopeOnlyTopLevel(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('parent_id'));
    }

    /** Income before expenses, then by category, then as entered. */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderByDesc('direction')->orderBy('category')->orderBy('id');
    }

    // Relationships

    /** @return BelongsTo<Armada, $this> */
    public function armada(): BelongsTo
    {
        return $this->belongsTo(Armada::class);
    }

    /** @return BelongsTo<Holding, $this> */
    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }

    /** @return BelongsTo<Holding, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Holding::class, 'account_id');
    }

    /** @return BelongsTo<Flow, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Flow, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Actual, $this> */
    public function actuals(): HasMany
    {
        return $this->hasMany(Actual::class);
    }
}
