<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\WotArticle;
use App\Models\WotEvent;

/**
 * @extends Factory<WotEvent>
 */
class WotEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(1, 20))->startOfHour();

        return [
            'wot_article_id' => WotArticle::factory(),
            'title' => fake()->sentence(4),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(6),
            'event_type' => null,
            'source' => WotEvent::SOURCE_WINDOW,
            'metadata' => null,
        ];
    }

    /**
     * An exact session from an article's own event calendar.
     */
    public function calendar(): static
    {
        return $this->state(fn (): array => [
            'source' => WotEvent::SOURCE_CALENDAR,
            'event_type' => 'stream',
        ]);
    }
}
