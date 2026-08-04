#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$ROOT_DIR/tests/cashier-v3-sale-only-submission"

node "$TEST_DIR/js/static-contract.mjs"
php "$TEST_DIR/php/preparation-identity-contract.php"
bash -n "$TEST_DIR/run-all.sh"
bash -n "$TEST_DIR/mysql56-matrix.sh"

if [[ "${SALE_ONLY_SUBMISSION_RUN_MYSQL56:-0}" == "1" ]]; then
  bash "$TEST_DIR/mysql56-matrix.sh"
else
  echo 'SALE_ONLY_SUBMISSION_MYSQL56=SKIPPED (set SALE_ONLY_SUBMISSION_RUN_MYSQL56=1)'
fi

echo 'SALE_ONLY_SUBMISSION_RUN_ALL=PASS'
