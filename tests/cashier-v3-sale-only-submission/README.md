# Sale-only checkout submission focused suite

This permanent suite closes the first supported final checkout slice through the
real `CashierV3ActionDispatcher` and Gateway transaction:

1. add one ordinary, non-inventory product to the authoritative workspace cart;
2. prepare the checkout request;
3. add the `wechat` bookkeeping payment method;
4. prepare final submission and consume the server-built resource plan;
5. submit the checkout and query the committed result.

The MySQL test asserts the settled sales order, collection, `checkout.completed`
event, sale/payment/actual-performance facts, request and child draft states,
resource-plan state, cart cleanup, recovery query, and succeeded root projection.
It also covers a failure raised at the final cart delete, proving that all earlier
writes roll back, plus exact same-key replay. This is a focused business-flow
gate, not a concurrency or pressure test.

## Run

Static checks and the direct PHP preparation-identity contract do not start Docker:

```bash
bash tests/cashier-v3-sale-only-submission/run-all.sh
```

The isolated database gate is opt-in and uses one disposable MySQL 5.6.51
container plus one PHP 7.4 container:

```bash
SALE_ONLY_SUBMISSION_RUN_MYSQL56=1 \
  bash tests/cashier-v3-sale-only-submission/run-all.sh
```

The runner never connects to the shared `mohe-mysql`, never copies the real
backend `.env`, and removes only its PID-qualified containers/network/temp data.
