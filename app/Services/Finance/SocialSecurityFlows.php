<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use App\Models\Finance\Flow;
use App\Models\Finance\Profile;
use App\Models\Finance\SocialSecurityStrategy;
use App\Models\User;

/**
 * Turns a claiming strategy into the Social Security income the rest of the
 * sub-project reads: one flow a person, starting the month they claim, at
 * what they would be paid in today's dollars, rising by the strategy's COLA.
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
                $existing = Flow::query()->onlyOwnedBy($user)->where('category', 'social_security')->where('name', self::NAMES[$key])->first();

                // Nothing to be paid is no income at all, and takes away one
                // an earlier strategy wrote.
                if ($claim['monthly'] <= 0) {
                    $existing?->delete();

                    continue;
                }

                $attributes = [
                    'direction' => 'income',
                    'category' => 'social_security',
                    'amount' => round($claim['monthly'], 2),
                    'frequency' => 'monthly',
                    'annual_growth_rate' => $strategy->cola_rate ?? $profile->inflation_rate,
                    'taxation' => config('finance.flow_categories.income.social_security.taxation'),
                    'taxed_portion' => $taxedPortion,
                    'starts_on' => $claim['starts_on'],
                    'ends_on' => "{$people[$key]['death_year']}-12-31",
                ];

                if ($existing) {
                    $existing->update($attributes);
                } else {
                    Flow::query()->create([...$attributes, 'user_id' => $user->id, 'name' => self::NAMES[$key], 'is_essential' => false]);
                }

                $written++;
            }

            return $written;
        });
    }
}
