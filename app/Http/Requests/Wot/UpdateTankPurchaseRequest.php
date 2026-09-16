<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\WotVehicle;
use App\Services\Wargaming\BlueprintCost;

/**
 * Ownership state for one vehicle. Nothing here comes from the API: the public
 * endpoints report what you have played, never what you have researched or what
 * a seasonal discount reduced the price to.
 */
class UpdateTankPurchaseRequest extends FormRequest
{
    /**
     * The fields the fragment ceiling applies to.
     *
     * Named here so the rules and the lookup guard cannot drift apart. Just the
     * one since the plan moved to its own request, where the nation it is
     * against is part of the URL.
     */
    private const FRAGMENT_FIELDS = ['blueprint_fragments'];

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fragments = ['sometimes', 'integer', 'min:0', 'max:'.$this->fragmentCeiling()];

        return [
            'is_unlocked' => ['sometimes', 'boolean'],
            'is_purchased' => ['sometimes', 'boolean'],
            // Nullable so clearing the field restores the encyclopedia price
            // rather than recording that the tank is free.
            'price_credit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            // Fragments built towards this vehicle's blueprint. The ceiling is
            // a game rule now rather than headroom: a blueprint takes what its
            // tier takes, and a figure above that buys nothing.
            'blueprint_fragments' => $fragments,
            // Membership of the Active Grinding list; there is no other tick
            // for "playing this", so adding and dropping are both this flag.
            'is_playing' => ['sometimes', 'boolean'],
            'banked_xp' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    /**
     * The most fragments this vehicle's blueprint could ever take.
     *
     * Behind hasAny() because every is_playing toggle and every banked_xp write
     * comes through this request, and none of them should pay for a vehicle
     * lookup to validate a field they did not send.
     *
     * Falls back to the largest count in the table for a tank that is not on
     * file, or one outside tiers II-X: an unknown tank should fail on the
     * controller's 404 rather than on a validation message about fragments.
     */
    private function fragmentCeiling(): int
    {
        if (! $this->hasAny(self::FRAGMENT_FIELDS)) {
            return BlueprintCost::MAX_FRAGMENTS;
        }

        $cost = app(BlueprintCost::class);
        $tier = (int) WotVehicle::where('tank_id', $this->route('tankId'))->value('tier');

        return $cost->supports($tier) ? $cost->fragmentsNeeded($tier) : BlueprintCost::MAX_FRAGMENTS;
    }
}
