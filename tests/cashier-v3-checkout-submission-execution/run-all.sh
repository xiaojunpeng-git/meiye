#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$ROOT_DIR/tests/cashier-v3-checkout-submission-execution"

bash "$TEST_DIR/run-static.sh"
php -l "$TEST_DIR/php/mysql-integration.php"
bash -n "$TEST_DIR/run-all.sh"
bash -n "$TEST_DIR/mysql56-matrix.sh"

if [[ "${MIXED_CHECKOUT_RUN_MYSQL56:-0}" == "1" ]]; then
  bash "$TEST_DIR/mysql56-matrix.sh"
else
  echo 'MIXED_CHECKOUT_MYSQL56=SKIPPED (set MIXED_CHECKOUT_RUN_MYSQL56=1)'
fi

echo 'MIXED_CHECKOUT_RUN_ALL=PASS'
