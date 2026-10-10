---
paths:
  - 'app/Models/Finance/*.php'
  - 'app/Models/Finance/Conversion*.php'
---

# Models Finance

## Read armada_key, not armada_id, and keep holding_id / account_id apart
Only top-level rows carry an armada. An account inside another, an item inside a household expense, and a flow hung off a holding all follow their owner — `armada_key` on Holding and Flow resolves that; the raw `armada_id` column on those rows is ignored (and the form requests null it). On a flow, `holding_id` is what it belongs to and `account_id` is the leaf asset it is paid into or out of in FleetLedger; they are different questions and often different holdings.

## A conversion strategy has no projection; the Roth report is ConversionReportEntry rows
Since 2026-10-08 `fin_conversion_strategies` has no `scenario_id` and no `is_compared`. The report's columns are `fin_conversion_report_entries` (strategy + nullable scenario, null = flows as entered), so one strategy edits once and can be reported on several projections. Don't put a projection back on the strategy, or in its form: a new strategy is in no report until added, and an edit never moves one. ConversionBoard::simulate()/inflationRate() take the scenario as an argument, and the page's `report` prop, Monte Carlo results and `years` are keyed by entry id, not strategy id. Writes go through ConversionStrategy::addToReport/duplicate/createStarters and ConversionReportEntry::replace, which hold the report to `finance.conversion_comparison.max`.
