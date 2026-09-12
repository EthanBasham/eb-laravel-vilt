<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\WotVehicle;

/**
 * @extends Factory<WotVehicle>
 */
class WotVehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->bothify('T-##?');

        return [
            'tank_id' => fake()->unique()->numberBetween(1, 100_000),
            'name' => $name,
            'short_name' => $name,
            'tier' => fake()->numberBetween(1, 10),
            'nation' => fake()->randomElement(['ussr', 'germany', 'usa', 'france', 'uk', 'china', 'japan']),
            'type' => fake()->randomElement(['heavyTank', 'mediumTank', 'lightTank', 'AT-SPG', 'SPG']),
            'is_premium' => false,
            'image_url' => null,
            // The full five-seat crew, which is what most vehicles carry. The
            // Crews board draws one letter per seat from this, so a vehicle
            // without it renders as an empty cell.
            'crew' => self::seats(['commander', 'gunner', 'driver', 'radioman', 'loader']),
        ];
    }

    /**
     * A vehicle whose crew is something other than the usual five.
     *
     * Each entry is a seat: either a role slug, or a list of them for one body
     * covering more than one job — the IS-7's fourth seat is a Loader who is
     * also the Radio Operator.
     *
     * @param  list<string|list<string>>  $seats
     */
    public function crew(array $seats): static
    {
        return $this->state(fn (): array => ['crew' => self::seats($seats)]);
    }

    /**
     * The encyclopedia's own crew shape.
     *
     * `member_id` names the seat's primary role and repeats within a vehicle —
     * an IS-7 carries two loaders — so nothing keys on it.
     *
     * @param  list<string|list<string>>  $seats
     * @return list<array{roles: array<string, string>, member_id: string}>
     */
    private static function seats(array $seats): array
    {
        $names = collect((array) config('wargaming.crew_roles'))->map(fn (array $role): string => $role['name']);

        return collect($seats)
            ->map(function (string|array $seat) use ($names): array {
                $roles = (array) $seat;

                return [
                    'roles' => collect($roles)->mapWithKeys(fn (string $role): array => [$role => $names[$role] ?? $role])->all(),
                    'member_id' => $roles[0],
                ];
            })
            ->all();
    }

    public function premium(): static
    {
        return $this->state(fn (): array => ['is_premium' => true]);
    }
}
