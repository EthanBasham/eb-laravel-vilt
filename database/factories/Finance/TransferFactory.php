<?php

namespace Database\Factories\Finance;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Finance\Holding;
use App\Models\Finance\Transfer;
use App\Models\User;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    protected $model = Transfer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'from_holding_id' => Holding::factory(),
            'to_holding_id' => Holding::factory(),
            'name' => fake()->words(2, true),
            'kind' => 'sweep',
            'amount' => null,
            'keep_balance' => 0,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    /** From one of a user's holdings to another. */
    public function between(Holding $from, Holding $to): static
    {
        return $this->state(['user_id' => $from->user_id, 'from_holding_id' => $from->id, 'to_holding_id' => $to->id]);
    }

    /**
     * A kind of transfer, with whatever settings it takes.
     *
     * @param  array<string, mixed>  $settings
     */
    public function ofKind(string $kind, array $settings = []): static
    {
        return $this->state(['kind' => $kind, ...$settings]);
    }
}
