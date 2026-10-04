<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Database\Factories\Finance\TransferFactory;

/**
 * A standing instruction to move money between two holdings each month:
 * sweep what is left in checking into savings, pay an extra $300 at the
 * mortgage, keep the emergency fund topped up.
 *
 * It holds the rule only. FleetLedger carries it out, month by month, in
 * `sort_order`.
 *
 * @property-read string $kind_label
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'from_holding_id', 'to_holding_id', 'name', 'kind', 'amount', 'keep_balance', 'sort_order', 'is_active'])]
class Transfer extends OwnedModel
{
    /** @use HasFactory<TransferFactory> */
    use HasFactory;

    protected $table = 'fin_transfers';

    /**
     * A transfer runs, and keeps nothing back, unless it says otherwise.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'keep_balance' => 0,
        'sort_order' => 0,
        'is_active' => true,
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
            'keep_balance' => 'float',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected function kindLabel(): Attribute
    {
        return Attribute::get(fn (): string => config("finance.transfer_kinds.{$this->kind}.label", $this->kind));
    }

    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'kind_label' => $this->kind_label,
            'from_holding_id' => $this->from_holding_id,
            'from_name' => $this->from?->full_name,
            'to_holding_id' => $this->to_holding_id,
            'to_name' => $this->to?->full_name,
            'amount' => $this->amount,
            'keep_balance' => $this->keep_balance,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ]);
    }

    // Scopes

    public function scopeOnlyActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The order they are carried out in each month. */
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    // Relationships

    /** @return BelongsTo<Holding, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Holding::class, 'from_holding_id');
    }

    /** @return BelongsTo<Holding, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Holding::class, 'to_holding_id');
    }
}
