---
paths:
  - 'app/Models/Finance/*.php'
---

# Models Finance

## Read armada_key, not armada_id, and keep holding_id / account_id apart
Only top-level rows carry an armada. An account inside another, an item inside a household expense, and a flow hung off a holding all follow their owner — `armada_key` on Holding and Flow resolves that; the raw `armada_id` column on those rows is ignored (and the form requests null it). On a flow, `holding_id` is what it belongs to and `account_id` is the leaf asset it is paid into or out of in FleetLedger; they are different questions and often different holdings.
