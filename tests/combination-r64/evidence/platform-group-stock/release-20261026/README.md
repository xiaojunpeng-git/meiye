# R64 platform group stock fix: release receipt

Business commit: `9b7d2e2773463b1931ee1f7a3e998f9ac7589939`. Branch: `release/r64-member-checkout-20261026`.

PM accepted source tree 1ac0b7a1. Sequence completed: commit -> RH deploy/verify -> push -> 008 deploy/verify -> 010 deploy/verify. Exactly ONE production file was deployed: app/services/order/StoreCartServices.php. Two regression files and this fix evidence are committed separately from unrelated workspace changes and pending mini-upload records.

| Instance | Result | Independent rollback point |
|---|---|---|
| rh | PASS | `/www/backups/rh.cc3798.com/20261026-r64-platform-stock-9b7d2e27` |
| 008 | PASS | `/www/backups/008.cc3798.com/20261026-r64-platform-stock-9b7d2e27` |
| 010 | PASS | `/www/backups/010.cc3798.com/20261026-r64-platform-stock-9b7d2e27` |

008 REAL existing cart was evaluated through production getUserProductCartListV1 before and after, as the actual worker account and using a READ ONLY SQL transaction. No cart, order, stock or payment was created/modified. Store 108 / activity 1: before valid=0, invalid=1, amount=0 with no-store-stock reason; after valid=1, invalid=0, amount=1 and no invalid reason. No UID, cart key, member details or credentials were exported. This is quote validation, NOT a successful real payment claim.

All protected frontend entry/assets, environment, configuration digests, migration records and untouched dependency fingerprints remain unchanged. All 79 worker query dependencies and public business read APIs pass. RH retired preview remains410. 008 retains its older card-sync-lock helper; the purchase read methods are identical to the canonical accepted helper. 010 retains its known older optional integral-ratio compatibility file. Neither unrelated difference is deployed or reverted.

Local checks: 48 platform zero-stock + 29 default + 42 legacy-switch-off + 3 actual frontend hydration/submit assertions. Inventory disabled, negative allowed, managed physical shortage/sufficiency, store ownership, applicability, group quota, unpaid receipt and idempotency are covered. SQL fixtures roll back and technical keys are cleaned. No schema/configuration/front-end/package changes or historical data edits. Existing mini upload tasks were not repeated.
