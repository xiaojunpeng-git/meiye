#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
test_dir="$repo_dir/tests/cashier-v3-checkout-facts"
migration_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-收银V3统一结账事实底座"

(cd "$migration_dir" && shasum -a 256 -c SHA256SUMS.txt)
node "$test_dir/js/static-contract.mjs"

if command -v php >/dev/null 2>&1; then
  find "$repo_dir/后端代码/app/services/cashier/v3/fact" "$test_dir/php" \
    -type f -name '*.php' -exec php -l {} \;
  CHECKOUT_FACT_BACKEND_ROOT="$repo_dir/后端代码" \
    php "$test_dir/php/contract.php"
  CHECKOUT_FACT_MIGRATION_DIR="$migration_dir" \
    php "$test_dir/php/migration-contract.php"
else
  echo 'PHP_STATIC_RUNTIME=UNAVAILABLE'
fi

if [[ "${CHECKOUT_FACT_SKIP_MYSQL56:-0}" != '1' ]]; then
  bash "$test_dir/mysql56-matrix.sh"
else
  echo 'CHECKOUT_FACT_MYSQL56_MATRIX=SKIPPED'
fi

echo 'CHECKOUT_FACT_FOCUSED_SUITE=PASS'
