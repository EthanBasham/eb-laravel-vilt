<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\Finance\Flow;
use App\Models\Finance\Profile;
use App\Models\Finance\SocialSecurityStrategy;
use App\Models\User;

/**
 * Turns a claiming strategy into the Social Security income the rest of the
 * sub-project reads: one flow a person, starting the month they claim, at
 * what they would be paid on their own record in today's dollars, rising by
 * the strategy's COLA. A spousal top-up is a second flow, because it starts
 * later — once both have claimed — and folded into the first it would be paid
 * for every month before that as well.
 *
 * The flows it writes are found again by name, so applying a second strategy
 * replaces the first's rather than adding to it. A Social Security income
 * under any other name is someone's own, and is left alone.
 *
 * What it cannot say: a flow is one amount on one schedule, so the step up a
 * survivor takes to the larger benefit is not written. Each flow ends with
 * the year its own person is planned to.
 */
class SocialSecurityFlows
{
    /** The names the written flows go by, keyed as SocialSecurityBoard keys its people. */
    public const NAMES = ['self' => 'Social Security', 'spouse' => 'Social Security (spouse)'];

    /** The names of the spousal top-ups, which begin later than the benefit they are added to. */
    public const TOP_UP_NAMES = ['self' => 'Social Security (spousal top-up)', 'spouse' => 'Social Security (spouse, spousal top-up)'];

    public function __construct(
        private SocialSecurityBoard $board,
        private Fleet $fleet,
    ) {}

    /**
     * @return int How many flows were written.
     */
    public function write(User $user, SocialSecurityStrategy $strategy): int
    {
        $profile = Profile::for($user);
        $people = $this->board->people($profile);
        $claims = $this->board->claims($strategy, $people);
        $taxedPortion = $this->board->for($user)['strategies']->firstWhere('id', $strategy->id)['summary']['taxable_share'] ?? 85.0;

        return DB::transaction(function () use ($user, $strategy, $profile, $people, $claims, $taxedPortion): int {
            $written = 0;

            foreach ($claims as $key => $claim) {
                $shared = [
                    'direction' => 'income',
                    'category' => 'social_security',
                    'frequency' => 'monthly',
                    'annual_growth_rate' => $strategy->cola_rate ?? $profile->inflation_rate,
                    'taxation' => config('finance.flow_categories.income.social_security.taxation'),
                    'taxed_portion' => $taxedPortion,
                    'ends_on' => "{$people[$key]['death_year']}-12-31",
                ];

                $written += $this->put($user, self::NAMES[$key], $claim['own'], [...$shared, 'starts_on' => $claim['starts_on']]);

                $written += $this->put($user, self::TOP_UP_NAMES[$key], $claim['top_up'], [
                    ...$shared,
                    'starts_on' => $claim['top_up_month'] === null ? null : Carbon::create(intdiv($claim['top_up_month'], 12), $claim['top_up_month'] % 12 + 1, 1)->toDateString(),
                ]);
            }

            return $written;
        });
    }

    /**
     * Writes one of the flows, replacing the one an earlier strategy wrote
     * under the same name. Nothing to be paid is no income at all, and takes
     * that earlier one away. Says how many flows it left written: one or none.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function put(User $user, string $name, float $monthly, array $attributes): int
    {
        $existing = Flow::query()->onlyOwnedBy($user)->where('category', 'social_security')->where('name', $name)->first();

        if ($monthly <= 0) {
            $existing?->delete();

            return 0;
        }

        $attributes = [...$attributes, 'amount' => round($monthly, 2)];

        if ($existing) {
            $existing->update($attributes);

            return 1;
        }

        Flow::query()->create([...$attributes, 'user_id' => $user->id, 'name' => $name, 'is_essential' => false]);

        return 1;
    }
}
