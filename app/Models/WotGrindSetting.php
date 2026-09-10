<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where each board's filter row was left, per account.
 *
 * It also carried two planning figures — credits to hand and vacant garage
 * slots — which were typed into a form nobody read the output of. The columns
 * went with the form.
 */
#[Fillable(['wot_account_id', 'purchase_filters', 'freexp_filters', 'xp_filters', 'blueprints_filters'])]
class WotGrindSetting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_filters' => 'array',
            'freexp_filters' => 'array',
            'xp_filters' => 'array',
            'blueprints_filters' => 'array',
        ];
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
