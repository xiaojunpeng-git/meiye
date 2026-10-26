#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
CARE_DIR="$ROOT_DIR/后端代码/app/services/customer/care"
PHP_BIN="${PHP_BIN:-php}"
PHP_IMAGE="${CUSTOMER_CARE_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"

node "$ROOT_DIR/tests/customer-care/js/static-contract.mjs"

if command -v "$PHP_BIN" >/dev/null 2>&1; then
  while IFS= read -r -d '' file; do
    "$PHP_BIN" -l "$file"
  done < <(find "$CARE_DIR" "$ROOT_DIR/tests/customer-care/php" -type f -name '*.php' -print0)

  "$PHP_BIN" "$ROOT_DIR/tests/customer-care/php/core-contract.php"
  "$PHP_BIN" "$ROOT_DIR/tests/customer-care/php/migration-contract.php"
  "$PHP_BIN" "$ROOT_DIR/tests/customer-care-document-number/php/migration-contract.php"
else
  echo "PHP_RUNTIME=container:$PHP_IMAGE"
  docker run --rm \
    -e CUSTOMER_CARE_BACKEND_ROOT=/backend \
    -e CUSTOMER_CARE_MIGRATION_DIR=/backend/database/upgrades/2026-07-29-客情任务与记录内核 \
    -v "$ROOT_DIR/后端代码:/backend:ro" \
    -v "$ROOT_DIR/tests:/tests:ro" \
    --entrypoint sh "$PHP_IMAGE" -lc '
      set -e
      find /backend/app/services/customer/care /tests/customer-care/php -type f -name "*.php" -exec php -l {} \;
      php /tests/customer-care/php/core-contract.php
      php /tests/customer-care/php/migration-contract.php
      php /tests/customer-care-document-number/php/migration-contract.php
    '
fi

if [[ "${CUSTOMER_CARE_SKIP_MYSQL56:-0}" != '1' ]]; then
  bash "$ROOT_DIR/tests/customer-care/sql-matrix.sh"
else
  echo "CUSTOMER_CARE_MYSQL56_MATRIX=SKIPPED"
fi

echo "CUSTOMER_CARE_FOCUSED_SUITE=PASS"
