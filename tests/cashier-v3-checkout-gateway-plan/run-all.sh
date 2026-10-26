#!/usr/bin/env bash
set -euo pipefail

TEST_DIR="$(cd "$(dirname "$0")" && pwd)"

node "$TEST_DIR/js/static-contract.mjs"
echo 'CHECKOUT_GATEWAY_PLAN_STATIC=PASS'

if command -v php >/dev/null 2>&1; then
  php -l "$TEST_DIR/php/contract.php"
  php "$TEST_DIR/php/contract.php"
  echo 'CHECKOUT_GATEWAY_PLAN_RUNTIME=PASS'
  echo 'CHECKOUT_GATEWAY_PLAN_SUITE=PASS'
else
  echo 'CHECKOUT_GATEWAY_PLAN_RUNTIME=SKIPPED_PHP_UNAVAILABLE'
fi
