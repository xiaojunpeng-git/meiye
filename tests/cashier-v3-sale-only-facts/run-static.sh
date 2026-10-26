#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
node "$ROOT/tests/cashier-v3-sale-only-facts/js/static-contract.mjs"

if command -v php >/dev/null 2>&1; then
  php "$ROOT/tests/cashier-v3-sale-only-facts/php/contract.php"
else
  echo "SKIP SALE_ONLY_FACT_PHP host php unavailable"
fi
