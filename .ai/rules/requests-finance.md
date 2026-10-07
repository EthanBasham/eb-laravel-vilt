---
paths:
  - 'app/Http/Requests/Finance/**'
---

# Requests Finance

## Extend FinanceRequest and use owned() for every id taken from input
Finance form requests extend FinanceRequest, not FormRequest. An id from input that names one of the user's rows is validated with `$this->owned(Model::class)` (chain further `where`s onto it) rather than a hand-written Rule::exists with a user_id constraint — forgetting that constraint lets a user point a record at somebody else's. Pinned-year keys in `overrides` are checked with `validatePinnedYears()`.
