# Mixed checkout production execution focused suite

This permanent suite verifies one complete `mixed` checkout through the real
Cashier V3 dispatcher, Gateway transaction, production execution port, and
MySQL 5.6 authorities. The scenario contains one ordinary sale product and one
existing entitlement project with cart quantity `3`. It uses a real internal
staff profile, project performance rule, inventory recipe/stock/batch, and C3
service-order occupation.

The database gate proves:

1. sales order, payment, entitlement receipt, inventory, service-order
   occupation, events, and facts commit as one business result;
2. entitlement and inventory quantities follow the authoritative cart quantity;
3. replaying the exact final body with the same idempotency key adds no writes;
4. result recovery returns both the real CSO and completed ECR;
5. a failure at final cart cleanup rolls every earlier domain write back.

This is a focused business-flow test, not a concurrency or pressure test.

## Run

Static contracts and syntax checks do not start Docker:

```bash
bash tests/cashier-v3-checkout-submission-execution/run-all.sh
```

The isolated database test is opt-in. It uses one disposable MySQL 5.6.51
container and one PHP 7.4 container:

```bash
MIXED_CHECKOUT_RUN_MYSQL56=1 \
  bash tests/cashier-v3-checkout-submission-execution/run-all.sh
```

The runner never connects to the shared `mohe-mysql`, never reads the real
backend `.env`, and removes only its PID-qualified containers, network, and
temporary environment directory.
