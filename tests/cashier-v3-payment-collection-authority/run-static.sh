#!/usr/bin/env bash
set -euo pipefail

test_dir="$(cd "$(dirname "$0")" && pwd)"
node "$test_dir/js/static-contract.mjs"
node "$test_dir/js/migration-contract.mjs"

if command -v php >/dev/null 2>&1; then
  find "$test_dir/php" -type f -name '*.php' -print0 \
    | xargs -0 -n1 php -l
  find "$test_dir/../../后端代码/app/services/cashier/v3/settlement/payment" \
    -type f -name '*.php' -print0 | xargs -0 -n1 php -l
  php "$test_dir/php/contract.php"
else
  echo 'SKIP PHP runtime contract: local php binary unavailable and Docker is forbidden for this task'
fi
