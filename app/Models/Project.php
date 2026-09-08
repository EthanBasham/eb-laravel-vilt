<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\ProjectFactory;

/**
 * A sub-project inside the VILT learning hub: one thing being built or studied,
 * with its own set of milestones.
 *
 * @property-read bool $is_published
 * @property-read array<int, string> $stack_items
 */
#[Fillable([
    'user_id', 'title', 'slug', 'summary', 'description', 'stack', 'repo_url', 'demo_url', 'is_featured', 'sort_order', 'published_at',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected function isPublished(): Attribute
    {
        return Attribute::get(fn (): bool => $this->published_at?->isPast() === true);
    }

    /**
     * The `stack` column split into individual technology labels.
     *
     * @return Attribute<array<int, string>, never>
     */
    protected function stackItems(): Attribute
    {
        return Attribute::get(fn (): array => collect(explode(',', (string) $this->stack))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all());
    }

    /**
     * How many milestones are done, out of how many exist — e.g. "3 of 7 complete".
     *
     * Reads from the already-loaded `milestones` relation rather than querying,
     * so calling this while rendering a list doesn't turn into N+1 selects.
     */
    public function progressLabel(): string
    {
        $milestones = $this->milestones;

        if ($milestones->isEmpty()) {
            return 'No milestones yet';
        }

        return sprintf('%d of %d complete', $milestones->where('is_complete', true)->count(), $milestones->count());
    }

    // Scopes

    public function scopeOnlyPublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
    public function scopeOnlyFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }
    public function scopeInDefaultOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('published_at');
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sort_order');
    }
}
