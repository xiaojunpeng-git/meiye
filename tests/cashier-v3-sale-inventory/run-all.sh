#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$ROOT/tests/cashier-v3-sale-inventory"

bash "$TEST_DIR/run-static.sh"
php -l "$TEST_DIR/php/mysql-integration.php"
bash -n "$TEST_DIR/mysql56-matrix.sh"

if [[ "${CASHIER_V3_SALE_INVENTORY_RUN_MYSQL56:-0}" == "1" ]]; then
  bash "$TEST_DIR/mysql56-matrix.sh"
  bash "$TEST_DIR/gateway-mysql56-matrix.sh"
else
  echo 'SALE_INVENTORY_MYSQL56=SKIPPED (set CASHIER_V3_SALE_INVENTORY_RUN_MYSQL56=1)'
fi

echo 'SALE_INVENTORY_RUN_ALL=PASS'
