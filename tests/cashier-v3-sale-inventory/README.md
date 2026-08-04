# Cashier V3 Sale Inventory

Focused permanent coverage for the ordinary-product inventory settlement path.

The suite verifies only the requested business boundary: real batch FEFO deduction, strict shortage rollback, idempotent receipt replay, a post-stock failure rolling back the outer transaction, and MySQL 5.6 compatibility. The Gateway matrix uses the actual `submit-checkout` command and verifies the inventory receipt, movement fact, sales order, payment collection, events, facts, checkout request, and command receipt commit or roll back together. It does not run pressure tests or migrate old inventory data.

Run the isolated database matrix with:

```bash
CASHIER_V3_SALE_INVENTORY_RUN_MYSQL56=1 \
  bash tests/cashier-v3-sale-inventory/run-all.sh
```
