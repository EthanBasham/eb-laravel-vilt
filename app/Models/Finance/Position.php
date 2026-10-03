<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Model;

/**
 * Something held inside an account: a fund, a stock, a coin.
 *
 * Owned through its holding rather than directly, so it extends the app's
 * base model and not OwnedModel — there is no `user_id` here to scope on.
 *
 * @property-read float $net_return
 * @property-read array<string, mixed> $props
 */
#[Fillable(['holding_id', 'name', 'symbol', 'asset_class', 'value', 'expected_return', 'expense_ratio'])]
class Position extends Model
{
    protected $table = 'fin_positions';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'float',
            'expected_return' => 'float',
            'expense_ratio' => 'float',
        ];
    }

    /** The expected return with the fund's own fee taken off. */
    protected function netReturn(): Attribute
    {
        return Attribute::get(fn (): float => $this->expected_return - $this->expense_ratio);
    }

    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'asset_class' => $this->asset_class,
            'asset_class_label' => config("finance.position_classes.{$this->asset_class}.label", $this->asset_class),
            'value' => $this->value,
            'expected_return' => $this->expected_return,
            'expense_ratio' => $this->expense_ratio,
        ]);
    }

    // Relationships

    /** @return BelongsTo<Holding, $this> */
    public function holding(): BelongsTo
    {
        return $this->belongsTo(Holding::class);
    }
}
