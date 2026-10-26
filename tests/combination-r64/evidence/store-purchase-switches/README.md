# R64 supplemental fix: group purchase versus retired pickup switches

The previously released cart validator still treated server-side group carts as legacy pickup and invalidated items when platform/store pickup settings were off. Creation also silently rewrote shipping_type=2 to shipping_type=1. Both were reproduced locally before changes.

The minimal fix recognizes a server-side group activity cart with an explicitly selected purchase store; it skips only the retired pickup/delivery-option gates. Creation preserves the authoritative purchase-store type for group orders. Existing ownership, applicable store, group quota, payment mode, idempotency and deferred fulfillment remain enforced. No history is rewritten; no new event, data model or migration is introduced. Payment and group completion still control issuance and formal sales visibility.

Source groups: two backend services and the existing rollback integration test. No frontend or migration changes. Complex branches explain their authority and exceptions in comments. Test failures now return nonzero after cleanup, since framework CLI exception rendering alone could exit zero.

Tests use local Docker mysql/ruihao only. Both global switches are set off transactionally; store and activity delivery settings exclude old pickup. Three product types confirm at 399 yuan, zero freight, no address, retain their store, create one unpaid order and a pending fulfillment receipt. Ownership, applicability, exhausted quota and non-group legacy restrictions are tested separately. SQL rolls back and fixture Redis keys/config cache are cleaned. The actual confirmation-page hydration and submit validator consume the disabled-switch controller responses. No production order/payment is made.

This is a newly fixed source tree awaiting product-manager acceptance; it has NOT been committed, deployed, pushed or uploaded. The two earlier mini upload tasks remain separate and pending confirmation in their own record.
