---
paths:
  - 'app/Models/Wot*Crew*.php'
  - 'app/Services/Wargaming/Crew*.php'
  - 'app/Http/Controllers/Wot/CrewController.php'
  - 'resources/js/wot/Pages/Crews.vue'
  - 'resources/js/wot/Components/Crew*.vue'
  - 'resources/js/wot/Components/Crews/**'
---

# Crews

## Crew slots key on position, never on the role
The encyclopedia's `member_id` repeats within a vehicle — an IS-7 carries two loaders — so a role cannot identify a seat. `wot_crew_members.slot` is the position in `wot_vehicles.crew`, and the role is deliberately *not* stored: the encyclopedia is the one description of what a vehicle's seats are, and a copy here would drift from it the first time a patch moves a tank's crew around.

A seat can also cover more than one job (the IS-7's fourth is a Loader who is also the Radio Operator). The board spells one letter per *body*, so the letter count equals the number of people to train; the extra roles only ever appear in the tooltip and the editor.

Seats are *displayed* in role order — C G D R L, the order of `config('wargaming.crew_roles')` — and not in the encyclopedia's own order, which differs between vehicles (an AT-1 lists its driver before its gunner). A column of cells that all read the same way is one a discrepancy jumps out of. `CrewBoard::members()` sorts by `roleRank()` with `slot` as the tiebreaker, so a vehicle's two loaders keep their relative order. The sort never touches `slot`: what is shown is reordered, what is written is not.

## An empty tank has no row
No crew is the absence of a `wot_tank_crews` row, not a row of zeroes. That absence is the board's red state, so the editor's empty action DELETEs. Writing zeroed members instead paints the cell as a crew that merely happens to be untrained, which is a different thing to report.

`is_balanced` defaults to false, so a crew that has not been vouched for reads as unbalanced and renders italic. That is intended — unknown and not-balanced are the same claim here.

## Balanced crews train as one, in the editor
While `is_balanced` is ticked, setting a seat's zero-skills, skill level or max sets every seat's — that is what the tick is for. Banked XP is the exception and stays per seat. This is why those three controls use `:value`/`@change` through `setOnMembers()` rather than `v-model`: restoring `v-model` silently reverts the linkage, and nothing in the test suite would catch it (the project has no JS test runner). Ticking the box deliberately does not reach back and level an already-mismatched crew — it takes effect from the next edit, so a tick never overwrites anything on its own.

## Nothing about a player's crew comes from the API
Wargaming publishes crew roles and the skills attached to them (`encyclopedia/crewroles`, `encyclopedia/crewskills`) and a vehicle's crew composition (`encyclopedia/vehicles.crew`). It publishes nothing whatever about a given player's tankmen — no names, training level, skills learned or banked XP. `tanks/crew`, `account/tankmen` and `encyclopedia/tankmen` are all METHOD_NOT_FOUND, and `account/info` has no crew field among its 302. Every figure on the Crews page is typed in by hand; do not go looking for an endpoint to sync it from.

## Board filters all live on wot_grind_settings
Every tech-tree board's filter row is a column on that one table, the Crews board's included, merged through `WotGrindSetting::mergeFilters()`. A new board adds a column and a value to `BoardFiltersRequest`'s `board` rule — not a table, and not a second copy of the merge.

## A crew guide stores only the included perks, and perk descriptions come from Wargaming's own text
`wot_crew_guide_roles.included` is the ordered list of perks to train; the excluded bucket is never stored. It is whatever else `config('wargaming.crew_role_perks')` lists for the role, worked out in PerkOrganizer.vue, so a perk a patch adds lands in excluded without a row being touched and CrewGuideBoard drops a stored perk the role can no longer train. A role with nothing saved has no row. Do not add an `excluded` column or a third "undecided" state.

The descriptions in `config('wargaming.crew_perks')` are Wargaming's own wording, copied from the article named in that file's header, not the API's (which is terse and missing for eleven perks). When a patch changes or adds a perk, take the new text from an official Wargaming page and say which; do not write game effects in from memory, and leave a description null (the tooltip handles it) where no such source exists.
