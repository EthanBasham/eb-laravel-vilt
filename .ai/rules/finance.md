---
paths:
  - 'app/Services/Finance/**'
---

# Finance

## Fleet::flows() is the top-level listing; use flowLeaves() for anything itemised
A flow can be compound (a "Household expenses" flow with items via fin_flows.parent_id), exactly as a holding can. Fleet::flows() returns top-level flows with children attached; summing those counts every dollar once because a compound flow's amounts are its items' sum. Anything that needs one row per amount (budget lines, scenario rows, actuals) must go through Fleet::flowLeaves() — ScenarioBoard::rows() already does. Never query Flow directly and total it: parents and items would both be counted.

## Balances and contributions must be in the same dollars; Monte Carlo is always today's
When a tool deflates a balance to today's dollars, deflate the deposits it is compared with too (Amortization::depositsInTodaysDollars) — a real balance less nominal deposits understates growth. ConversionBoard::simulate() reports each year's own dollars by default and today's with `inTodaysDollars: true`; the page gets both. ConversionMonteCarlo always asks for today's dollars, because each market has its own inflation. Social Security spousal top-ups are written as their own flow (SocialSecurityFlows::TOP_UP_NAMES) starting when both have claimed.
