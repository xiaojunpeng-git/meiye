# C3 Service Order Occupation Authority Permanent Tests

Run the focused gate:

```bash
bash tests/c3-service-order-authority/run-all.sh
```

Set `C3_SERVICE_ORDER_SKIP_MYSQL56=1` only for a non-final static/pure-contract pass.

This suite covers the production C3 authority contract without activating Gateway. It proves
the exact C2 contributor shape, complete active service-order aggregation, direct/reservation/
service-order source behavior, DataScope, deterministic guard/order/line locking, MySQL 5.6.51
migration replay and partial-DDL rejection, real ThinkPHP transaction enforcement, and an
empty-range concurrency case where an authority scan blocks a concurrent first occupation
insert on the entitlement guard.
