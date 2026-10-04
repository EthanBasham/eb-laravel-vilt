<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Models\User;

/**
 * The few facts about a person the tools need to place them on a tax table
 * and a retirement timeline.
 *
 * @property-read int $age
 * @property-read int $birth_year
 * @property-read int $retirement_year
 * @property-read int $rmd_start_age
 * @property-read bool $has_state_tax
 * @property-read ?string $state_label
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'birth_date', 'filing_status', 'retirement_age', 'life_expectancy', 'inflation_rate', 'state', 'state_deduction', 'state_brackets', 'local_name', 'local_deduction', 'local_brackets', 'standard_deduction', 'se_tax_rate', 'ltcg_brackets', 'ss_monthly_benefit', 'spouse_birth_date', 'spouse_ss_monthly_benefit', 'spouse_life_expectancy'])]
class Profile extends OwnedModel
{
    protected $table = 'fin_profiles';

    /**
     * What a profile reads as before its owner has filled one in.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'filing_status' => 'single',
        'retirement_age' => 65,
        'life_expectancy' => 92,
        'inflation_rate' => 2.5,
        'state_deduction' => 0,
        'local_deduction' => 0,
        'se_tax_rate' => 15.3,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'retirement_age' => 'integer',
            'life_expectancy' => 'integer',
            'inflation_rate' => 'float',
            'state_deduction' => 'float',
            'state_brackets' => 'array',
            'local_deduction' => 'float',
            'local_brackets' => 'array',
            'standard_deduction' => 'float',
            'se_tax_rate' => 'float',
            'ltcg_brackets' => 'array',
            'ss_monthly_benefit' => 'float',
            'spouse_birth_date' => 'date',
            'spouse_ss_monthly_benefit' => 'float',
            'spouse_life_expectancy' => 'integer',
        ];
    }

    /**
     * This user's profile, or an unsaved one carrying the defaults.
     *
     * Not firstOrCreate: every page in the sub-project reads the profile, and
     * a GET should not write a row just because someone looked.
     */
    public static function for(User $user): self
    {
        return static::query()->onlyOwnedBy($user)->first() ?? new static(['user_id' => $user->id]);
    }

    /**
     * Age this calendar year. Year arithmetic, because that is how the IRS
     * counts it for RMDs: the age you turn in the year, not the age today.
     *
     * 40 stands in for someone who has not given a birth date, so the tools
     * render something rather than nothing; `props.has_birth_date` lets the
     * page say that it is a stand-in.
     */
    protected function age(): Attribute
    {
        return Attribute::get(fn (): int => now()->year - $this->birth_year);
    }

    protected function birthYear(): Attribute
    {
        return Attribute::get(fn (): int => $this->birth_date?->year ?? now()->year - 40);
    }

    protected function retirementYear(): Attribute
    {
        return Attribute::get(fn (): int => $this->birth_year + $this->retirement_age);
    }

    protected function rmdStartAge(): Attribute
    {
        return Attribute::get(fn (): int => (int) config(
            $this->birth_year >= 1960 ? 'finance.rmd.start_age.from_1960' : 'finance.rmd.start_age.before_1960',
        ));
    }

    /** Whether any state or local bracket is saved, so there is tax to add. */
    protected function hasStateTax(): Attribute
    {
        return Attribute::get(fn (): bool => ! empty($this->state_brackets) || ! empty($this->local_brackets));
    }

    /** "New York" for a preset's key; null for none or for "other". */
    protected function stateLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => config("finance.tax.states.{$this->state}.label"));
    }

    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'birth_date' => $this->birth_date?->toDateString(),
            'has_birth_date' => $this->birth_date !== null,
            'age' => $this->age,
            'filing_status' => $this->filing_status,
            'retirement_age' => $this->retirement_age,
            'life_expectancy' => $this->life_expectancy,
            'inflation_rate' => $this->inflation_rate,
            'rmd_start_age' => $this->rmd_start_age,
            'state' => $this->state,
            'state_label' => $this->state_label,
            'state_deduction' => $this->state_deduction,
            'state_brackets' => $this->state_brackets ?? [],
            'local_name' => $this->local_name,
            'local_deduction' => $this->local_deduction,
            'local_brackets' => $this->local_brackets ?? [],
            'has_state_tax' => $this->has_state_tax,
            // Null on either means the built-in figure for the filing status.
            'standard_deduction' => $this->standard_deduction,
            'ltcg_brackets' => $this->ltcg_brackets,
            'se_tax_rate' => $this->se_tax_rate,
        ]);
    }
}
