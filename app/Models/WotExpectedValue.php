<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * XVM's expected performance for one vehicle — the baseline WN8 measures
 * against. Rows are owned by the upstream feed and replaced wholesale by
 * `wot:sync-expected-values`.
 */
#[Fillable(['tank_id', 'exp_damage', 'exp_spot', 'exp_frag', 'exp_def', 'exp_win_rate', 'version'])]
class WotExpectedValue extends Model
{
    protected $primaryKey = 'tank_id';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tank_id' => 'integer',
            'exp_damage' => 'float',
            'exp_spot' => 'float',
            'exp_frag' => 'float',
            'exp_def' => 'float',
            'exp_win_rate' => 'float',
        ];
    }
}
