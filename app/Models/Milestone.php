<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Database\Factories\MilestoneFactory;

/**
 * A single checkpoint within a Project — "wire up Inertia", "write the first
 * Pest test", and so on.
 *
 * @property-read bool $is_complete
 */
#[Fillable(['project_id', 'title', 'notes', 'sort_order', 'completed_at'])]
class Milestone extends Model
{
    /** @use HasFactory<MilestoneFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    protected function isComplete(): Attribute
    {
        return Attribute::get(fn (): bool => $this->completed_at !== null);
    }

    /**
     * Mark this milestone done or not-done.
     *
     * Completing an already-complete milestone leaves the original timestamp
     * alone, so a stray double-toggle doesn't rewrite when the work happened.
     */
    public function markComplete(bool $complete): void
    {
        if ($complete) {
            $this->update(['completed_at' => $this->completed_at ?? now()]);

            return;
        }

        $this->update(['completed_at' => null]);
    }

    // Scopes

    public function scopeOnlyComplete(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }
    public function scopeNotComplete(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    // Relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
