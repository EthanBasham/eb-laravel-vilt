---
paths:
  - 'app/Http/Controllers/Finance/**'
---

# Controllers Finance

## Finance controllers stay thin; ownership is checked twice on purpose
Page props come from a `*Board::for()` (HoldingBoard, CashflowBoard, SettingsBoard, ...). Multi-step writes live on the model (Scenario::adjustFlow/adjustHolding/duplicate/setRateForDirection, ConversionStrategy::createStarters/duplicate/replaceComparison, Actual::record, Profile::saveFor). OwnedModel::resolveRouteBinding() already 404s another user's row before validation runs; the `abort_unless($model->isOwnedBy(...), 404)` line in each action is kept as a second check — do not remove either without the owner's say-so.
