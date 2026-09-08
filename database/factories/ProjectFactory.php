<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Project;
use App\Models\User;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->catchPhrase();

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'slug' => Str::slug($title),
            'summary' => fake()->sentence(12),
            'description' => fake()->paragraphs(3, true),
            'stack' => implode(', ', fake()->randomElements(
                ['Vue', 'Inertia', 'Laravel', 'Tailwind', 'jQuery', 'SASS', 'Vite', 'PostgreSQL'],
                3,
            )),
            'repo_url' => 'https://github.com/example/'.Str::slug($title),
            'demo_url' => null,
            'is_featured' => false,
            'sort_order' => fake()->numberBetween(0, 50),
            'published_at' => fake()->dateTimeBetween('-1 year', '-1 day'),
        ];
    }

    /**
     * A project that hasn't been published yet, so it's invisible to the public
     * routes.
     */
    public function draft(): static
    {
        return $this->state(fn (): array => ['published_at' => null]);
    }

    /**
     * Published, but dated in the future — should behave exactly like a draft.
     */
    public function scheduled(): static
    {
        return $this->state(fn (): array => ['published_at' => now()->addWeek()]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }
}
