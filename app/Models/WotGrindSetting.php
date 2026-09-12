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
#[Fillable(['wot_account_id', 'purchase_filters', 'freexp_filters', 'xp_filters', 'blueprints_filters', 'crews_filters'])]
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
            'crews_filters' => 'array',
        ];
    }

    /**
     * Merge one board's changed filters into what is stored for an account.
     *
     * Merged rather than replaced, so a client that sends one changed filter
     * does not silently reset the others beside it.
     *
     * Here rather than in a controller because two pages save filters — the
     * grinding boards and the Crews board — against this one row, and the rule
     * about merging is the same for both.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function mergeFilters(WotAccount $account, string $board, array $filters): void
    {
        $settings = self::firstOrNew(['wot_account_id' => $account->id]);

        $column = $board.'_filters';

        $settings->{$column} = [...(array) $settings->{$column}, ...$filters];

        $settings->save();
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
