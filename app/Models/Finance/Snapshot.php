<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The fleet's totals on a day, with the projection made from them that day.
 */
#[Fillable(['user_id', 'taken_on', 'assets', 'liabilities', 'net_worth', 'projection', 'note'])]
class Snapshot extends OwnedModel
{
    protected $table = 'fin_snapshots';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taken_on' => 'date',
            'assets' => 'float',
            'liabilities' => 'float',
            'net_worth' => 'float',
            'projection' => 'array',
        ];
    }

    // Scopes

    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('taken_on')->orderBy('id');
    }
}
