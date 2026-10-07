<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Database\Factories\Finance\SocialSecurityStrategyFactory;

/**
 * One answer to "when do we claim Social Security": an age for each person,
 * and the two rates the comparison is run under.
 *
 * Settings only; the benefits are worked out by SocialSecurityBoard.
 *
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'name', 'claim_age', 'claim_months', 'spouse_claim_age', 'spouse_claim_months', 'cola_rate', 'discount_rate'])]
class SocialSecurityStrategy extends OwnedModel
{
    /** @use HasFactory<SocialSecurityStrategyFactory> */
    use HasFactory;

    protected $table = 'fin_social_security_strategies';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'claim_months' => 0,
        'spouse_claim_months' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claim_age' => 'integer',
            'claim_months' => 'integer',
            'spouse_claim_age' => 'integer',
            'spouse_claim_months' => 'integer',
            'cola_rate' => 'float',
            'discount_rate' => 'float',
        ];
    }

    /**
     * The three ages worth setting side by side before anything has been
     * decided: as early as possible, at full retirement age, and at 70.
     *
     * @param  array{self: array<string, mixed>, spouse?: array<string, mixed>}  $people  SocialSecurityBoard::people(): each person's full retirement age (`fra`), in months.
     */
    public static function createStarters(User $user, array $people): void
    {
        $earliest = (int) config('finance.social_security.earliest_age');
        $latest = (int) config('finance.social_security.latest_age');

        $starters = [
            "Claim at {$earliest}" => fn (array $person): array => [$earliest, 0],
            'Claim at full retirement age' => fn (array $person): array => [intdiv($person['fra'], 12), $person['fra'] % 12],
            "Wait until {$latest}" => fn (array $person): array => [$latest, 0],
        ];

        DB::transaction(function () use ($user, $people, $starters): void {
            foreach ($starters as $name => $ageOf) {
                [$age, $months] = $ageOf($people['self']);
                [$spouseAge, $spouseMonths] = isset($people['spouse']) ? $ageOf($people['spouse']) : [null, 0];

                static::query()->create([
                    'user_id' => $user->id,
                    'name' => $name,
                    'claim_age' => $age,
                    'claim_months' => $months,
                    'spouse_claim_age' => $spouseAge,
                    'spouse_claim_months' => $spouseMonths,
                ]);
            }
        });
    }

    /** The settings as saved, nulls and all: what the edit form is filled from. */
    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'name' => $this->name,
            'claim_age' => $this->claim_age,
            'claim_months' => $this->claim_months,
            'spouse_claim_age' => $this->spouse_claim_age,
            'spouse_claim_months' => $this->spouse_claim_months,
            'cola_rate' => $this->cola_rate,
            'discount_rate' => $this->discount_rate,
        ]);
    }

    // Scopes

    /** As added, so a new strategy takes the next column and the next colour. */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('id');
    }
}
