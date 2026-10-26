#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
test_dir="$repo_dir/tests/cashier-v3-member-balance"
migration_dir="$repo_dir/后端代码/database/upgrades/2026-07-29-收银V3会员余额权威"
source_dir="$repo_dir/后端代码/app/services/cashier/v3/checkout/provider"

(cd "$migration_dir" && shasum -a 256 -c SHA256SUMS.txt)
node "$test_dir/js/static-contract.mjs"
bash -n "$test_dir/run-all.sh" "$test_dir/mysql56-matrix.sh"

if command -v php >/dev/null 2>&1; then
  for file in \
    "$source_dir/CashierV3MemberBalanceContractException.php" \
    "$source_dir/CashierV3MemberBalanceProvider.php" \
    "$source_dir/CashierV3MemberBalanceWriterAdapter.php" \
    "$test_dir/php/contract.php" \
    "$test_dir/php/migration-contract.php" \
    "$test_dir/php/mysql-bootstrap.php" \
    "$test_dir/php/mysql-integration.php" \
    "$test_dir/php/mysql-concurrency.php" \
    "$test_dir/php/concurrency-worker.php"; do
    php -l "$file"
  done
  CHECKOUT_BALANCE_BACKEND_ROOT="$repo_dir/后端代码" php "$test_dir/php/contract.php"
  CHECKOUT_BALANCE_MIGRATION_DIR="$migration_dir" php "$test_dir/php/migration-contract.php"
else
  echo 'PHP_STATIC_RUNTIME=UNAVAILABLE'
fi

if [[ "${CHECKOUT_BALANCE_SKIP_MYSQL56:-0}" != '1' ]]; then
  bash "$test_dir/mysql56-matrix.sh"
else
  echo 'CHECKOUT_BALANCE_MYSQL56_MATRIX=SKIPPED'
fi

echo 'CHECKOUT_BALANCE_FOCUSED_SUITE=PASS'
