<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Database\Factories\WotAccountFactory;

/**
 * Links a local user to their Wargaming account, and holds the OpenID access
 * token issued for it.
 *
 * @property-read bool $is_token_valid
 */
#[Fillable(['user_id', 'account_id', 'nickname', 'access_token', 'access_token_expires_at', 'last_synced_at'])]
#[Hidden(['access_token'])]
class WotAccount extends Model
{
    /** @use HasFactory<WotAccountFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            // Encrypted at rest. The token grants access to this player's
            // private account data, so a database leak shouldn't hand it over.
            'access_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    protected function isTokenValid(): Attribute
    {
        return Attribute::get(fn (): bool => $this->access_token !== null
            && $this->access_token_expires_at?->isFuture() === true);
    }

    /**
     * Forget the token without unlinking the account.
     *
     * Used when Wargaming rejects it — the link and account id are still
     * correct, only the credential is spent.
     */
    public function forgetToken(): void
    {
        $this->update(['access_token' => null, 'access_token_expires_at' => null]);
    }

    // Scopes

    public function scopeOnlyTokenExpiring(Builder $query, int $withinHours = 48): Builder
    {
        return $query->whereNotNull('access_token')
            ->whereBetween('access_token_expires_at', [now(), now()->addHours($withinHours)]);
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
