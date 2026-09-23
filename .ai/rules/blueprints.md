---
paths:
  - 'app/Services/Wargaming/Blueprint*.php'
  - 'resources/js/wot/Components/Blueprint*.vue'
  - 'resources/js/wot/Components/Grinding/Blueprint*.vue'
  - 'resources/js/wot/Pages/Grinding.vue'
---

# Blueprints

## The last fragment covers the remainder, not its listed share
`config('wargaming.blueprint_costs')` gives each tier a `percent`, and every fragment removes that share of the vehicle's base research XP *except the last*, which takes whatever is left and lands the tank on nothing to research. Tier X is eleven fragments at 7% and then 23%, not twelve at 7% — multiply it out as `fragments × percent` and a completed blueprint leaves 16% of the price still owed.

`BlueprintCost::xpSaved()` gets this from its `>= fragmentsNeeded()` branch rather than from a special case, and rounds once on the cumulative share rather than per fragment and summing. Both are pinned by tests; changing either silently moves every figure on the board.

## The two cost columns are an AND; only the payer is a choice
`national` and `universal` are both spent on every fragment — one fragment costs that many national blueprints *and* that many universal ones. `group` is not a third price: it is what the national half costs when a peer nation in the vehicle's group pays it instead of the vehicle's own, at six to one, and the universal half does not move when it does. There is no fragment bought with universal blueprints alone, and none bought without them.

This corrected a reading recorded on 2026-09-15 — that the three columns were alternatives chosen per fragment — which under-quoted every plan by roughly a third and put a "Universal" row in the planner for a purchase that cannot happen. `wot_tank_purchases.blueprint_plan` replaced the three counters that reading needed: a JSON map of nation slug to fragments, written one nation per request at `grinding.blueprint-plan`, because each line of the planner is an independent decision.

The map counts *fragments*. What they come to in blueprints is `BlueprintCost::plan()`, and it needs the tier and the vehicle's nation — there is no summing the map without both.

## A plan names its nation; the board still measures nothing against stock
A plan used to say only "somewhere in the group", so charging any one nation's stack for it would have been invented. That is no longer true — `blueprint_plan` is keyed by nation, and `BlueprintCost::payingNations()` is the whole of which nations may appear: the vehicle's own and the peers in its group, own first. A blueprint never leaves its group, so a nation outside it is a 404 rather than a validation error.

The board still measures no plan against any stock. `blueprints.stock` is raw material recorded, `blueprints.planned` is a total across every nation together, and the planner prints "N held" beside a line to be read, not to report a shortfall. A per-nation shortfall is answerable now rather than invented — but it is still not claimed anywhere, and adding it is a decision, not a tidy-up.

## Three different things all sound like "blueprints"
`wot_tank_purchases.blueprint_fragments` is fragments built towards one vehicle. `wot_blueprints.quantity` is the raw material they are crafted from, held per nation with `universal` as a twelfth row. `wot_tank_purchases.research_xp` is neither — it is what a player read off the game screen after the game applied the discount, and it is kept even though the discount is now derivable. `unlocks.blueprint_xp` on the XP board is the derived one. The two are shown side by side where they differ precisely because the board cannot tell which is stale.

## The board computes, the page renders
There is no JS test runner in this project, so any fragment arithmetic done in Vue is arithmetic nothing checks. Every figure `BlueprintPlanner.vue` shows — `xp_after_plan`, `planned.national_blueprints`, the per-fragment `cost` — arrives on `blueprints.rows.*.cells.*` where `GrindingTest` can assert it. Keep it that way rather than multiplying counts out in a template.

## Blueprints are tiers II to X
`BlueprintBoard::MIN_TIER`/`MAX_TIER` and the keys of `blueprint_costs` are the same claim written twice; keep them agreeing. A tier I is researched from nothing and a tier XI sits above where the system stops, so `BlueprintCost::supports()` is false for both and the derived XP on the XP board is null there — which is not the same as zero.

## The planner binds by tank_id, not by the cell object
Every write in the modal reloads `blueprints` wholesale while it is still open, so a stored cell object becomes a snapshot from before the edit — showing exactly the figures the save was meant to change. `Components/Grinding/BlueprintsBoard.vue` holds `editing` as an id and re-resolves `editingCell` each render, preferring the owning (non-shared) cell. `Components/Crews/CrewBoard.vue` stores the object instead and gets away with it only because `CrewEditor` saves once and closes.
