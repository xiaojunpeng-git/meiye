# Cashier V3 checkout result query

Permanent DB-free contracts for the read-only `query-checkout-result` service.

The service uses the original `submit-checkout` idempotency key and the current
server-created operator/DataScope. A successful receipt is not enough: the
settled sale-only order and succeeded checkout request must both match before a
minimal success DTO is returned. Missing, processing, or inconsistent evidence
stays `result_unknown`; this path never creates a command, business row, fact,
event, or Outbox record.

Run without Docker:

```bash
bash tests/cashier-v3-checkout-result-query/run-static.sh
```
