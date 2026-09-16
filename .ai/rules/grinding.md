---
paths:
  - app/Http/Controllers/Wot/GrindController.php
  - app/Services/Wargaming/FreeXpBoard.php
  - 'app/Models/WotTank*.php'
  - 'resources/js/wot/Components/Module*.vue'
---

# Grinding

## Applying a Free XP plan must not spend banked XP
`updateModuleResearch()` and `researchAllModules()` charge the tank's `banked_xp` for every module they tick, because researching one in game spends what the tank has accumulated. `applyModulePlan()` deliberately does not: Free XP is a separate pool, and paying out of it is exactly how a module is researched *without* touching the tank's balance. Charging there would take the XP twice — the Active Grinding row would show a banked figure the garage does not.

All three write the same `researched_module_ids`, so the difference is invisible from the model. It lives in the controller alone, and is pinned by "leaves banked XP alone when the plan is paid with Free XP" in `tests/Feature/Wot/GrindingTest.php`.

## is_maxed is the Free XP board's question; is_researched is XP Remaining's
A Free XP cell carries both. `is_maxed` is every upgrade module researched (or none there to begin with) — nothing left for Free XP to buy. `is_researched` is that *and* every tank ahead unlocked, which is what XP Remaining counts and what `GrindBoard::researchCounts()` reports as "vehicles researched".

They part company on a tier X with a stock gun under an unlocked-nothing tier XI: it owes the unlock, so it is not researched, but no part of that debt is payable in Free XP, so it is maxed. The Lines filter on the Free XP tab hides by `is_maxed` — reading `is_researched` there left 15 of 67 finished lines on the board, each over a single cell with nothing in it to buy, and no tier filter could shift them because the stuck cell was the tier X, not the tier XI. Do not collapse the two flags back into one.
