---
paths:
  - 'tests/Feature/Wot/**'
---

# Wot

## Patch first, render once — AccountProgress memoises across requests in a test
AccountProgress is bound as a singleton (AppServiceProvider) and memoises ownership per account. That is per-request in production, but a test case shares one container across every `$this->get()`/`$this->patch()`, so a render before a PATCH poisons the render after it: the second read returns the first's answer and the assertion fails (or worse, passes on stale data).

All four grinding boards read it — Tanks to Purchase does too, since is_unlocked moved there from LineOwnership — so this now affects `purchase.*` props as well as `xp.*`, `freexp.*` and `blueprints.*`.

Do every PATCH first, then assert with a single `get(route('wot.grinding'))`. If a test needs a before-and-after, make it two tests.
