<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            'wot_bookmarks_seeded_at' => 'datetime',
        ];
    }

    // Relationships

    /** @return HasOne<WotAccount, $this> */
    public function wotAccount(): HasOne
    {
        return $this->hasOne(WotAccount::class);
    }

    /**
     * The links in the strip under the World of Tanks header.
     *
     * On the user rather than on wotAccount, unlike the rest of the sub-project:
     * the bar is up before an account is linked.
     *
     * @return HasMany<WotBookmark, $this>
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(WotBookmark::class);
    }

    /** @return BelongsToMany<WotArticle, $this> */
    public function pinnedArticles(): BelongsToMany
    {
        return $this->belongsToMany(WotArticle::class, 'wot_article_pins')->withPivot('pinned_at');
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
}
