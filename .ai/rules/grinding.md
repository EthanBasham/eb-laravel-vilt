---
paths:
  - app/Http/Controllers/Wot/GrindController.php
  - 'app/Models/WotTank*.php'
  - 'resources/js/wot/Components/Module*.vue'
---

# Grinding

## Applying a Free XP plan must not spend banked XP
`updateModuleResearch()` and `researchAllModules()` charge the tank's `banked_xp` for every module they tick, because researching one in game spends what the tank has accumulated. `applyModulePlan()` deliberately does not: Free XP is a separate pool, and paying out of it is exactly how a module is researched *without* touching the tank's balance. Charging there would take the XP twice — the Active Grinding row would show a banked figure the garage does not.

All three write the same `researched_module_ids`, so the difference is invisible from the model. It lives in the controller alone, and is pinned by "leaves banked XP alone when the plan is paid with Free XP" in `tests/Feature/Wot/GrindingTest.php`.
