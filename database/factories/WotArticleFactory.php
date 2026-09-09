<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\WotArticle;

/**
 * @extends Factory<WotArticle>
 */
class WotArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug();

        return [
            'guid' => "https://worldoftanks.com/en/news/specials/{$slug}/",
            'url' => "https://worldoftanks.com/en/news/specials/{$slug}/",
            'title' => fake()->sentence(6),
            'description' => fake()->sentence(15),
            'category' => 'Specials',
            'image_url' => null,
            'published_at' => now()->subDays(fake()->numberBetween(0, 30)),
            'body_fetched_at' => null,
            'body_hash' => null,
        ];
    }
}
