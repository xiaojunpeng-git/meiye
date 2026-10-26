#!/usr/bin/env bash
set -euo pipefail

test_dir="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$test_dir/../.." && pwd)"

node "$test_dir/js/static-contract.mjs"

php_files=(
  "$root/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutResultReadRepository.php"
  "$root/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutResultReadRepository.php"
  "$root/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutResultQueryServices.php"
  "$test_dir/php/contract.php"
)
if command -v php >/dev/null 2>&1; then
  for file in "${php_files[@]}"; do
    php -l "$file"
  done
  php "$test_dir/php/contract.php"
else
  echo 'SKIP PHP runtime contract: local php binary unavailable and Docker is forbidden for this task'
fi
