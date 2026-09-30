---
paths:
  - 'ml/payment-risk/**'
---

# Payment Risk

## Keep training data private and activation gated
Accept only the exact private source CSV and provenance contract; never commit source data or deployable models. Require reversal-free point-in-time provenance, keep synthetic artifacts activation-ineligible, and write portable artifacts only to private storage after all declared quality and held-out activation gates pass.
