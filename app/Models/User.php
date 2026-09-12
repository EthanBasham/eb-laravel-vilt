<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Relationships

    /** @return HasOne<WotAccount, $this> */
    public function wotAccount(): HasOne
    {
        return $this->hasOne(WotAccount::class);
    }

    /** @return BelongsToMany<WotArticle, $this> */
    public function pinnedArticles(): BelongsToMany
    {
        return $this->belongsToMany(WotArticle::class, 'wot_article_pins')
            ->withPivot('pinned_at')
            ->orderByPivot('pinned_at', 'desc');
    }

    /** @return BelongsToMany<WotArticle, $this> */
    public function seenArticles(): BelongsToMany
    {
        return $this->belongsToMany(WotArticle::class, 'wot_article_views')->withPivot('seen_at');
    }

    /** @return BelongsToMany<WotEvent, $this> */
    public function ignoredEvents(): BelongsToMany
    {
        return $this->belongsToMany(WotEvent::class, 'wot_event_ignores')->withPivot('ignored_at');
    }

    /**
     * Records an article as seen, leaving an existing timestamp alone — "first
     * seen" is the useful fact, and re-reading something shouldn't make it look
     * freshly discovered.
     *
     * Note this is attach-guarded rather than syncWithoutDetaching, which would
     * rewrite the pivot on every call.
     */
    public function markArticleSeen(WotArticle $article): void
    {
        if ($this->seenArticles()->whereKey($article->id)->exists()) {
            return;
        }

        $this->seenArticles()->attach($article->id, ['seen_at' => now()]);
    }
}
