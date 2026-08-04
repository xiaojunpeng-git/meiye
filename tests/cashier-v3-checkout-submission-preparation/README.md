# Cashier V3 checkout submission preparation

This isolated contract covers the eventless transition from an editing checkout
request at version `N` to `ready_for_submit` at version `N+1`.

The first activated slice is intentionally limited to `sale_only` with no
balance deduction, debt, or entitlement lines. Gateway must have revalidated and
locked every context before the service runs. The persisted resource plan is
built from the server discovery pack plus locked navigation sources such as a
service order, hang order, reservation, or room. It excludes the public
workspace and checkout-request contexts and is bound to the new request version.

Run `./run-static.sh`. The script always runs the Node static contract. When a
host PHP binary is available it also runs PHP lint and the DB-free behavioral
contract. It never starts Docker.
