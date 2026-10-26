# Cashier V3 checkout resource plan focused suite

This suite protects the server-only, version-bound checkout pre-lock plan.

Static contract:

```bash
bash tests/cashier-v3-checkout-resource-plan/run-static.sh
```

Explicit MySQL 5.6.51 window:

```bash
CHECKOUT_RESOURCE_PLAN_RUN_MYSQL56=1 bash tests/cashier-v3-checkout-resource-plan/run-all.sh
```

The suite does not activate Gateway or write business facts. Docker execution is opt-in so other shared suites are not started concurrently.

It permanently checks that plan state transitions lock and verify the checkout
request before a plan header, checkout request CAS can supersede older active
plans without deleting immutable rows, and all resources reuse the global
`lockOrder -> kind -> canonical resource id` comparator. Canonical positive
decimal IDs use overflow-safe numeric order; opaque IDs use byte order.
The inventory fragment includes `inventory_shortage_cursor(56)` whenever an
allow-shortage provider can lock that dynamic resource; a plan that omits a
physical lock is not activation-ready.
