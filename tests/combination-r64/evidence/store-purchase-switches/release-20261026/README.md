# R64 supplemental group-purchase switch fix: release receipt

Business commit: `d8cb007223f5b7bb703683146806349b42f6089c`. Branch: `release/r64-member-checkout-20261026`.

Authorized sequence completed: commit -> RH deploy/verify -> push -> 008 deploy/verify -> 010 deploy/verify. Only StoreCartServices.php and StoreOrderCreateServices.php were deployed. The test and diagnostics are part of the exact commit allowlist; unrelated local changes and pending mini-upload records were excluded.

| Instance | Result | Independent rollback location |
|---|---|---|
| rh | PASS | `/www/backups/rh.cc3798.com/20261026-r64-store-switch-d8cb0072` |
| 008 | PASS | `/www/backups/008.cc3798.com/20261026-r64-store-switch-d8cb0072` |
| 010 | PASS | `/www/backups/010.cc3798.com/20261026-r64-store-switch-d8cb0072` |

Before and after fingerprints verify unchanged client bundles, environment, configuration digests, migration records and untouched backend dependencies. Worker-account loading of all 79 query dependencies and public business APIs passed. Retired RH preview remains 410. Local regression covered 39 disabled-switch assertions plus 26 default-mode assertions, actual frontend hydration, allowed store boundaries, quota, unpaid pending fulfillment and idempotency. No production order, real payment, history edit, database migration, setting change, frontend rebuild or mini-program reupload occurred.
