<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Something being saved towards: an amount, by a date.
 *
 * @property-read int $months_remaining
 * @property-read float $remaining_amount
 */
#[Fillable(['user_id', 'name', 'target_amount', 'saved_amount', 'target_date'])]
class Goal extends OwnedModel
{
    protected $table = 'fin_goals';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_amount' => 'float',
            'saved_amount' => 'float',
            'target_date' => 'date',
        ];
    }

    /**
     * Whole months from this month to the target's month, never less than
     * one — a goal due this month still has this month's deposit to make, and
     * a past-due one should not divide by zero.
     */
    protected function monthsRemaining(): Attribute
    {
        return Attribute::get(fn (): int => max(1, (int) now()->startOfMonth()->diffInMonths($this->target_date->copy()->startOfMonth(), false)));
    }

    protected function remainingAmount(): Attribute
    {
        return Attribute::get(fn (): float => max(0.0, $this->target_amount - $this->saved_amount));
    }

    // Scopes

    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('target_date')->orderBy('id');
    }
}
