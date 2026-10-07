<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\Finance\ArmadaFactory;

/**
 * A named part of the fleet: the holdings that belong together, with the
 * income and expenses that come with them — "Real estate", "Foundational",
 * "Retirement".
 *
 * It holds nothing but its name. What is in it is whatever points at it, and
 * what it comes to is worked out by ArmadaBoard.
 *
 * @property-read array<string, mixed> $props
 */
#[Fillable(['user_id', 'name', 'description'])]
class Armada extends OwnedModel
{
    /** @use HasFactory<ArmadaFactory> */
    use HasFactory;

    protected $table = 'fin_armadas';

    protected function props(): Attribute
    {
        return Attribute::get(fn (): array => [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
        ]);
    }

    // Scopes

    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('name')->orderBy('id');
    }
}
