---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## No private methods on controllers
Controllers hold only their public actions. Put extra logic in the action itself, or in a service that is injected into the action. Page-building services follow the `*Board` convention: a class whose `for()` returns page props (CalendarBoard, BlueprintBoard). Derived per-model logic goes on the model as accessors or methods. DashboardController, CrewController and GrindController still have private methods from before this rule. Move them out when you touch those methods; don't add new ones.
