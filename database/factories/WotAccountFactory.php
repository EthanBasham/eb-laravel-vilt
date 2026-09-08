<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\User;
use App\Models\WotAccount;

/**
 * @extends Factory<WotAccount>
 */
class WotAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'nickname' => fake()->userName(),
            'access_token' => fake()->sha256(),
            'access_token_expires_at' => now()->addWeeks(2),
            'last_synced_at' => null,
        ];
    }

    /** A link whose token has run out — still connected, can't authenticate. */
    public function expiredToken(): static
    {
        return $this->state(fn (): array => ['access_token_expires_at' => now()->subDay()]);
    }

    public function withoutToken(): static
    {
        return $this->state(fn (): array => [
            'access_token' => null,
            'access_token_expires_at' => null,
        ]);
    }
}
