<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\WotVehicle;
use App\Services\Wargaming\BlueprintCost;

/**
 * How many of one vehicle's blueprint fragments a single nation is to pay for.
 *
 * The nation is in the path rather than here, like the blueprint stock it will
 * be spent from: a nation that cannot pay for this vehicle is a URL that does
 * not exist, which the controller answers, and not a field that failed to
 * validate.
 */
class UpdateBlueprintPlanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Fragments, not blueprints — what they cost is the tier's
             * business. Capped at what the blueprint takes, the same ceiling
             * blueprint_fragments carries: a plan for more fragments than the
             * vehicle has room for buys nothing.
             *
             * Bounded per nation rather than across them. A plan that overshoots
             * by spreading itself over three nations is a real thing to type on
             * the way to changing your mind, and the planner says so in words
             * rather than refusing the keystroke.
             */
            'fragments' => ['required', 'integer', 'min:0', 'max:'.$this->fragmentCeiling()],
        ];
    }

    /**
     * The most fragments this vehicle's blueprint could ever take.
     *
     * Falls back to the largest count in the table for a tank that is not on
     * file, or one outside tiers II-X: an unknown tank should fail on the
     * controller's 404 rather than on a message about fragments.
     */
    private function fragmentCeiling(): int
    {
        $cost = app(BlueprintCost::class);
        $tier = (int) WotVehicle::where('tank_id', $this->route('tankId'))->value('tier');

        return $cost->supports($tier) ? $cost->fragmentsNeeded($tier) : BlueprintCost::MAX_FRAGMENTS;
    }
}
