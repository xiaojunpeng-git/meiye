#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$ROOT_DIR/tests/cashier-v3-checkout-request-authority"
MIGRATION_DIR="$ROOT_DIR/后端代码/database/upgrades/2026-07-29-收银V3结账请求来源权威"

(cd "$MIGRATION_DIR" && shasum -a 256 -c SHA256SUMS.txt)
node "$TEST_DIR/js/static-contract.mjs"

if command -v php >/dev/null 2>&1; then
  find "$ROOT_DIR/后端代码/app/services/cashier/v3/settlement" "$TEST_DIR/php" \
    -type f -name '*.php' -exec php -l {} \;
  php "$TEST_DIR/php/source-set-contract.php"
  php "$TEST_DIR/php/migration-contract.php"
else
  echo 'PHP_STATIC_RUNTIME=UNAVAILABLE'
fi

echo 'CHECKOUT_REQUEST_AUTHORITY_STATIC=PASS'
