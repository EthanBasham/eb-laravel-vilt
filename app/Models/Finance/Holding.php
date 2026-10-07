<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\Finance\HoldingFactory;

/**
 * One row of the fleet: an asset or a liability.
 *
 * A holding can be compound: a retirement account holding several brokerage
 * accounts is a parent row with a child row for each (`parent_id`). A parent
 * with children is worth their sum, grows at their blended rate, and takes
 * their contributions; its own balance, rate and contribution are ignored
 * while it has any. A child is taxed as its parent is.
 *
 * `value`, `expected_rate` and `contribution` read the children and the
 * positions, so load both before reading any of them across a list —
 * Fleet::holdings() does.
 *
 * @property-read bool $is_compound
 * @property-read float $contribution
 * @property-read string $full_name
 * @property-read bool $is_asset
 * @property-read float $value
 * @property-read float $signed_value
 * @property-read float $expected_rate
 * @property-read string $type_label
 * @property-read string $group
 * @property-read string $tax_treatment
 * @property-read bool $is_investable
 * @property-read ?int $armada_key
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'armada_id', 'parent_id', 'side', 'type', 'plan_type', 'tax_type', 'name', 'institution', 'balance', 'annual_rate', 'monthly_contribution', 'secured_by_id', 'notes'])]
class Holding extends OwnedModel
{
    /** @use HasFactory<HoldingFactory> */
    use HasFactory;

    protected $table = 'fin_holdings';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'float',
            'annual_rate' => 'float',
            'monthly_contribution' => 'float',
        ];
    }

    protected function isAsset(): Attribute
    {
        return Attribute::get(fn (): bool => $this->side === 'asset');
    }

    /** Whether other holdings sit inside this one. */
    protected function isCompound(): Attribute
    {
        return Attribute::get(fn (): bool => $this->children->isNotEmpty());
    }

    /**
     * What the holding is worth: the sum of the accounts inside it when it is
     * compound, else the sum of its positions when it has any, else its own
     * balance.
     */
    protected function value(): Attribute
    {
        return Attribute::get(function (): float {
            if ($this->is_compound) {
                return (float) $this->children->sum->value;
            }

            if ($this->positions->isNotEmpty()) {
                return (float) $this->positions->sum('value');
            }

            return $this->balance;
        });
    }

    /** Positive for an asset, negative for a liability — what it adds to net worth. */
    protected function signedValue(): Attribute
    {
        return Attribute::get(fn (): float => $this->is_asset ? $this->value : -$this->value);
    }

    /**
     * The annual rate the projector should use. For a compound holding, its
     * children's rates weighted by value. Otherwise the positions' returns net of
     * their expense ratios, weighted by value, when there are positions; the
     * holding's own rate when there are none (or when they are worth nothing,
     * which would otherwise divide by zero).
     */
    protected function expectedRate(): Attribute
    {
        return Attribute::get(function (): float {
            if ($this->is_compound) {
                $inside = $this->value;

                if ($inside > 0) {
                    return $this->children->sum(fn (self $child): float => $child->value * $child->expected_rate) / $inside;
                }

                return $this->annual_rate;
            }

            $total = (float) $this->positions->sum('value');

            if ($total > 0) {
                return $this->positions->sum(fn (Position $position): float => $position->value * $position->net_return) / $total;
            }

            return $this->annual_rate;
        });
    }

    /**
     * What goes in (or, for a debt, is paid) each month: the children's
     * contributions for a compound holding, its own otherwise.
     */
    protected function contribution(): Attribute
    {
        return Attribute::get(function (): float {
            if ($this->is_compound) {
                return (float) $this->children->sum->contribution;
            }

            return $this->monthly_contribution;
        });
    }

    /** "Work 401(k) › Fidelity account" for a child; the plain name otherwise. */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => $this->parent ? "{$this->parent->name} › {$this->name}" : $this->name);
    }

    /**
     * A retirement account reads as its two facts — "Roth IRA", "Traditional
     * 401(k)" — rather than as the bare type.
     */
    protected function typeLabel(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->plan_type && $this->tax_type) {
                return config("finance.retirement_tax_types.{$this->tax_type}.label", $this->tax_type)
                    .' '.config("finance.retirement_plans.{$this->plan_type}.label", $this->plan_type);
            }

            return config("finance.holding_types.{$this->type}.label", $this->type);
        });
    }

    protected function group(): Attribute
    {
        return Attribute::get(fn (): string => config("finance.holding_types.{$this->type}.group", 'Other'));
    }

    /**
     * How money inside is taxed: `deferred`, `free` or `taxable`.
     *
     * An account inside another is taxed as the outer one is — a brokerage
     * account held in a Roth IRA is Roth money. A retirement account goes by
     * its own `tax_type`. Everything else goes by its type.
     */
    protected function taxTreatment(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->parent) {
                return $this->parent->tax_treatment;
            }

            return config("finance.retirement_tax_types.{$this->tax_type}.tax")
                ?? config("finance.holding_types.{$this->type}.tax", 'taxable');
        });
    }

    /** Whether the Portfolio Projector and the retirement tools pick it up. */
    protected function isInvestable(): Attribute
    {
        return Attribute::get(function (): bool {
            if ($this->parent) {
                return $this->parent->is_investable;
            }

            return (bool) config("finance.holding_types.{$this->type}.investable", false);
        });
    }

    /**
     * The armada the holding sails in. An account inside another goes where
     * the outer one goes, whatever its own column says.
     */
    protected function armadaKey(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->parent ? $this->parent->armada_id : $this->armada_id);
    }

    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'side' => $this->side,
            'armada_id' => $this->armada_key,
            'parent_id' => $this->parent_id,
            'type' => $this->type,
            'plan_type' => $this->plan_type,
            'tax_type' => $this->tax_type,
            'type_label' => $this->type_label,
            'group' => $this->group,
            'name' => $this->name,
            'institution' => $this->institution,
            'balance' => $this->balance,
            'value' => $this->value,
            'annual_rate' => $this->annual_rate,
            'expected_rate' => round($this->expected_rate, 3),
            'monthly_contribution' => $this->monthly_contribution,
            'contribution' => $this->contribution,
            'children_count' => $this->children->count(),
            'secured_by_id' => $this->secured_by_id,
            'notes' => $this->notes,
            'positions_count' => $this->positions->count(),
        ]);
    }

    // Scopes

    public function scopeOnlyAssets(Builder $query): Builder
    {
        return $query->where('side', 'asset');
    }

    public function scopeOnlyLiabilities(Builder $query): Builder
    {
        return $query->where('side', 'liability');
    }

    /** Holdings that stand on their own, not inside another. */
    public function scopeOnlyTopLevel(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('parent_id'));
    }

    /**
     * Assets before liabilities, then largest balance first. The balance
     * column only: Fleet::holdings() re-sorts by `value`, which also counts
     * what is inside a holding.
     */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('side')->orderByDesc('balance')->orderBy('id');
    }

    // Relationships

    /** @return BelongsTo<Armada, $this> */
    public function armada(): BelongsTo
    {
        return $this->belongsTo(Armada::class);
    }

    /** @return BelongsTo<Holding, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Holding, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Position, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /** @return HasMany<Flow, $this> */
    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }

    /** @return BelongsTo<Holding, $this> */
    public function securedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'secured_by_id');
    }

    /** @return HasMany<Holding, $this> */
    public function securedDebts(): HasMany
    {
        return $this->hasMany(self::class, 'secured_by_id');
    }
}
