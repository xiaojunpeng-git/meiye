#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
PHP_IMAGE="${CUSTOMER_CARE_QUERY_PHP_IMAGE:-docker.m.daocloud.io/phpswoole/swoole:4.8.13-php7.4-alpine}"

node "$ROOT_DIR/tests/customer-care-query/js/static-contract.mjs"

if command -v php >/dev/null 2>&1; then
  find \
    "$ROOT_DIR/后端代码/app/services/customer/care/query" \
    "$ROOT_DIR/后端代码/app/services/customer/care/integration" \
    "$ROOT_DIR/tests/customer-care-query/php" \
    -type f -name '*.php' -print0 | while IFS= read -r -d '' file; do
      php -l "$file"
    done
  php "$ROOT_DIR/tests/customer-care-query/php/contract.php"
else
  echo "CUSTOMER_CARE_QUERY_PHP_RUNTIME=container:$PHP_IMAGE"
  docker run --rm \
    -v "$ROOT_DIR/后端代码:/backend:ro" \
    -v "$ROOT_DIR/tests:/tests:ro" \
    --entrypoint sh "$PHP_IMAGE" -lc '
      set -e
      find /backend/app/services/customer/care/query \
        /backend/app/services/customer/care/integration \
        /tests/customer-care-query/php \
        -type f -name "*.php" -exec php -l {} \;
      CUSTOMER_CARE_QUERY_BACKEND=/backend php /tests/customer-care-query/php/contract.php
    '
fi

if [[ "${CUSTOMER_CARE_QUERY_SKIP_MYSQL56:-0}" != '1' ]]; then
  bash "$ROOT_DIR/tests/customer-care-query/sql-matrix.sh"
else
  echo 'CUSTOMER_CARE_QUERY_MYSQL56_MATRIX=SKIPPED'
fi

echo 'CUSTOMER_CARE_QUERY_FOCUSED_SUITE=PASS'
