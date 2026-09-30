---
paths:
  - '{app/Http/Middleware/EnsureFamilySubscription.php,routes/web.php}'
---

# Middleware

## Strict missing-plan checks are route opt-in
Preserve the legacy pass-through when no assigned or active free plan resolves for existing subscription feature routes. Sensitive new routes may opt into fail-closed plan resolution with the second middleware parameter `strict`; Payment Risk must keep `subscription:reports,strict` together with its report authorization and PredictiveAnalytics Pennant gate. Resolved plans always enforce their feature list.
