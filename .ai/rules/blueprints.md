---
paths:
  - 'app/Services/Wargaming/Blueprint*.php'
  - 'resources/js/wot/Components/Blueprint*.vue'
  - 'resources/js/wot/Pages/Grinding.vue'
---

# Blueprints

## The last fragment covers the remainder, not its listed share
`config('wargaming.blueprint_costs')` gives each tier a `percent`, and every fragment removes that share of the vehicle's base research XP *except the last*, which takes whatever is left and lands the tank on nothing to research. Tier X is eleven fragments at 7% and then 23%, not twelve at 7% — multiply it out as `fragments × percent` and a completed blueprint leaves 16% of the price still owed.

`BlueprintCost::xpSaved()` gets this from its `>= fragmentsNeeded()` branch rather than from a special case, and rounds once on the cumulative share rather than per fragment and summing. Both are pinned by tests; changing either silently moves every figure on the board.

## The three cost columns are an OR, chosen per fragment
`national`, `group` and `universal` are what one fragment costs from each source, not a combined price. A fragment is paid for out of exactly one of them, and a single blueprint can take a different one each time. That is why `wot_tank_purchases` carries three plan counters instead of one: the group rate is six times the national one, so a single "planned" figure could not be turned back into blueprints.

The counters count *fragments*. What they come to in raw blueprints is `BlueprintCost::plan()`, and it needs the tier — there is no summing them without it.

## Group spend belongs to the group, never to a nation
A group fragment eats six blueprints of *some other* nation in the vehicle's group, and which one is settled when it is spent, not when it is planned. Nothing may charge a nation's stack for it. The Blueprints board deliberately measures no plan against any stock at all — `blueprints.stock` is raw material recorded, and `blueprints.planned` is a total. Anything that starts reporting a per-nation shortfall is inventing a debt.

## Three different things all sound like "blueprints"
`wot_tank_purchases.blueprint_fragments` is fragments built towards one vehicle. `wot_blueprints.quantity` is the raw material they are crafted from, held per nation with `universal` as a twelfth row. `wot_tank_purchases.research_xp` is neither — it is what a player read off the game screen after the game applied the discount, and it is kept even though the discount is now derivable. `unlocks.blueprint_xp` on the XP board is the derived one. The two are shown side by side where they differ precisely because the board cannot tell which is stale.

## The board computes, the page renders
There is no JS test runner in this project, so any fragment arithmetic done in Vue is arithmetic nothing checks. Every figure `BlueprintPlanner.vue` shows — `xp_after_plan`, `planned.national_blueprints`, the per-fragment `cost` — arrives on `blueprints.rows.*.cells.*` where `GrindingTest` can assert it. Keep it that way rather than multiplying counts out in a template.

## Blueprints are tiers II to X
`BlueprintBoard::MIN_TIER`/`MAX_TIER` and the keys of `blueprint_costs` are the same claim written twice; keep them agreeing. A tier I is researched from nothing and a tier XI sits above where the system stops, so `BlueprintCost::supports()` is false for both and the derived XP on the XP board is null there — which is not the same as zero.

## The planner binds by tank_id, not by the cell object
Every write in the modal reloads `blueprints` wholesale while it is still open, so a stored cell object becomes a snapshot from before the edit — showing exactly the figures the save was meant to change. `Grinding.vue` holds `bpEditing` as an id and re-resolves `bpEditingCell` each render, preferring the owning (non-shared) cell. `Crews.vue` stores the object instead and gets away with it only because `CrewEditor` saves once and closes.
