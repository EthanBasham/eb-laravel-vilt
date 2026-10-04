---
paths:
  - 'app/Services/Finance/**'
---

# Finance

## Fleet::flows() is the top-level listing; use flowLeaves() for anything itemised
A flow can be compound (a "Household expenses" flow with items via fin_flows.parent_id), exactly as a holding can. Fleet::flows() returns top-level flows with children attached; summing those counts every dollar once because a compound flow's amounts are its items' sum. Anything that needs one row per amount (budget lines, scenario rows, actuals) must go through Fleet::flowLeaves() — ScenarioBoard::rows() already does. Never query Flow directly and total it: parents and items would both be counted.
