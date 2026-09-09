<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One capture of an account's lifetime totals. Period statistics are the
 * difference between two of these.
 */
#[Fillable(['wot_account_id', 'captured_at', 'battles', 'statistics'])]
class WotSnapshot extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'battles' => 'integer',
            'statistics' => 'array',
        ];
    }

    // Scopes

    public function scopeOnlyAtOrBefore(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('captured_at', '<=', $moment);
    }

    // Relationships

    public function account(): BelongsTo
    {
        return $this->belongsTo(WotAccount::class, 'wot_account_id');
    }
}
