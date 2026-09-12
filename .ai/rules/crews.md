---
paths:
  - 'app/Models/Wot*Crew*.php'
  - 'app/Services/Wargaming/Crew*.php'
  - 'app/Http/Controllers/Wot/CrewController.php'
  - 'resources/js/wot/Pages/Crews.vue'
  - 'resources/js/wot/Components/Crew*.vue'
---

# Crews

## Crew slots key on position, never on the role
The encyclopedia's `member_id` repeats within a vehicle — an IS-7 carries two loaders — so a role cannot identify a seat. `wot_crew_members.slot` is the position in `wot_vehicles.crew`, and the role is deliberately *not* stored: the encyclopedia is the one description of what a vehicle's seats are, and a copy here would drift from it the first time a patch moves a tank's crew around.

A seat can also cover more than one job (the IS-7's fourth is a Loader who is also the Radio Operator). The board spells one letter per *body*, so the letter count equals the number of people to train; the extra roles only ever appear in the tooltip and the editor.

## An empty tank has no row
No crew is the absence of a `wot_tank_crews` row, not a row of zeroes. That absence is the board's red state, so the editor's empty action DELETEs. Writing zeroed members instead paints the cell as a crew that merely happens to be untrained, which is a different thing to report.

`is_balanced` defaults to false, so a crew that has not been vouched for reads as unbalanced and renders italic. That is intended — unknown and not-balanced are the same claim here.

## Balanced crews train as one, in the editor
While `is_balanced` is ticked, setting a seat's zero-skills, skill level or max sets every seat's — that is what the tick is for. Banked XP is the exception and stays per seat. This is why those three controls use `:value`/`@change` through `setOnMembers()` rather than `v-model`: restoring `v-model` silently reverts the linkage, and nothing in the test suite would catch it (the project has no JS test runner). Ticking the box deliberately does not reach back and level an already-mismatched crew — it takes effect from the next edit, so a tick never overwrites anything on its own.

## Nothing about a player's crew comes from the API
Wargaming publishes crew roles and the skills attached to them (`encyclopedia/crewroles`, `encyclopedia/crewskills`) and a vehicle's crew composition (`encyclopedia/vehicles.crew`). It publishes nothing whatever about a given player's tankmen — no names, training level, skills learned or banked XP. `tanks/crew`, `account/tankmen` and `encyclopedia/tankmen` are all METHOD_NOT_FOUND, and `account/info` has no crew field among its 302. Every figure on the Crews page is typed in by hand; do not go looking for an endpoint to sync it from.

## Board filters all live on wot_grind_settings
Every tech-tree board's filter row is a column on that one table, the Crews board's included, merged through `WotGrindSetting::mergeFilters()`. A new board adds a column and a value to `BoardFiltersRequest`'s `board` rule — not a table, and not a second copy of the merge.
