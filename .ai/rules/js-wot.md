---
paths:
  - 'resources/js/wot/**'
---

# Js Wot

## Tech-tree boards are assembled from shared components, not written out
Every board on /wot/grinding and /wot/crews is the same four parts: BoardFilterPanel (nation + tier chips, plus a slot for the board's own checkbox), TechTreeBoard (sticky line column, tier columns, totals footer), a cell component, and useGrindBoard for the state. Add a board by composing those, never by copying another board's markup — the five hand-written copies this replaced had already drifted from each other.

useGrindBoard returns a `reactive` bundle (filters + shownRows/shownTiers + rowTotal/tierTotal/grandTotal) and is instantiated on the *page*, not in the board component: two headline cards report a filtered board total and stay on show while only one tab's table is mounted. A board component receives that bundle as its `board` prop and may write to it (`v-model="board.hide_done"`) — that is what persists the filter, since useBoardFilters watches those refs.

What a board owns is only its `rules(filters)`: `cellValue` (which must return 0 for a shared cell, so row totals sum to the grand total), `keepRow`, and optional `isDone`. Keep arithmetic out of templates — there is no JS test runner here, so a figure computed in markup is a figure nothing checks.

Formatters live in lib/format.js (n, number, short, inK, roman, asDate…). Do not re-declare `const n = …` or a ROMAN array in a component; nine copies of the first and six of the second is how the dashboard ended up with a tier list that stopped at X.
